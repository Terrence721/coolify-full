<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesApplicationHeading;
use App\Http\Controllers\Concerns\ManagesResourceDanger;
use App\Http\Controllers\Concerns\ManagesResourceEnvironmentVariables;
use App\Http\Controllers\Concerns\ManagesResourceLimits;
use App\Http\Controllers\Concerns\ManagesResourceOperations;
use App\Http\Controllers\Concerns\ManagesResourceScheduledTasks;
use App\Http\Controllers\Concerns\ManagesResourceStorages;
use App\Http\Controllers\Concerns\ManagesResourceTags;
use App\Http\Controllers\Concerns\ManagesResourceWebhooks;
use App\Http\Controllers\Concerns\ResolvesProjectResources;
use App\Actions\Application\StopApplicationOneServer;
use App\Actions\Docker\GetContainersStatus;
use App\Events\ApplicationStatusChanged;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Rules\ValidGitBranch;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Url\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Visus\Cuid2\Cuid2;

/**
 * React port of App\Livewire\Project\Application\Configuration's shell plus the tabs it shares
 * with the already-fully-converted Database/Service routers: Tags, Danger Zone, Resource
 * Limits, Resource Operations's generic "move" half, and Scheduled Tasks (Phase 63, already
 * Application|Service-typed since Phase 58); Environment Variables (production set only) and
 * Persistent Storage (Phase 65, on their third consumer — both concerns needed real widening,
 * not just wiring, since neither had ever seen a non-service-non-database resource before: see
 * ManagesResourceEnvironmentVariables' usesDockerCompose()/dockerComposeContent() and
 * ManagesResourceStorages' configurationDir()/requiresHostPath()); Webhooks (Phase 66, its
 * third consumer for the shared read-only deploy webhook, plus the manual Git secrets form
 * that's genuinely Application-only — see ManagesResourceWebhooks' docblock); Swarm (Phase
 * 67, no concern needed — it's Application's own deprecated feature with no Database/Service
 * equivalent at all); and Rollback (Phase 68, likewise Application-only — the docker-images-
 * to-keep setting plus the local-image list/rollback-deploy actions, which genuinely touch SSH
 * for their happy path, same untested-happy-path gap as every other SSH-touching conversion —
 * see docs/smoketest.md). "Clone" is Application's own — it delegates to clone_application(),
 * the comprehensive helper already proven by Project\CloneMe, rather than duplicating
 * per-child-type cloning logic inline the way Database's and Service's clone() methods each had
 * to.
 *
 * The shell's heading (deploy/restart/stop/force-deploy/status-polling — Phase 64) is
 * ApplicationHeading.jsx, built from ManagesApplicationHeading's props on its second
 * consumer (the first being ProjectLogsController's application logs page, ported earlier
 * alongside deploy/restart/stop/check-status themselves). No new deployment routes were
 * needed here — only the props pointing at the existing ones.
 *
 * General (Phase 69, the largest tab in this whole migration — the main build-pack/deployment-
 * configuration form: name, git source display, build-pack-specific settings, domains/redirect,
 * Docker registry, network, HTTP basic auth, container labels, pre/post-deployment commands).
 * Kept inline in this controller rather than a shared concern, matching Service's own General
 * tab precedent (Phase 59) — both are single-resource-type forms with no Database/Service
 * equivalent worth abstracting over. `updatedBuildPack()`'s pre-submit side effects are folded
 * into updateGeneral() itself via a client-sent `buildPackChanged` flag rather than a separate
 * route, since the original always ends by calling the equivalent of `submit()` anyway. The
 * compose-file-load/parse path is genuinely SSH-touching, carrying the standard untested-happy-
 * path gap (docs/smoketest.md).
 *
 * Preview Deployments (Phase 70, folding in what used to be three separate Livewire components —
 * Previews, its PreviewsCompose child, and Preview\Form — into one tab, one controller, one React
 * component). Application-only, no shared concern. Found and fixed two real pre-existing bugs
 * while porting: `checkDomainUsage()` silently ignored its `$domain` parameter whenever a
 * `$resource` was also passed (used `$resource->fqdns` instead), meaning the original's own
 * preview-domain conflict check was a silent no-op — fixed by preferring the explicit `$domain`
 * when both are given, a backward-compatible change since no other call site had ever passed
 * both; and `ApplicationPreview::generate_preview_fqdn()`/`generate_preview_fqdn_compose()`
 * passed the (int-cast) `pull_request_id` column straight into `str_replace()`, which throws
 * under PHP 8's `strict_types=1` — fixed with an explicit `(string)` cast.
 *
 * Advanced (Phase 71 — Build/Container/Deployment/Git/Docker Compose/Proxy/Logs instant-save
 * settings, plus the Custom Container Name/Stop Grace Period/Max Restart Count standalone forms
 * and the GPU section's own explicit-Save-button form). Application-only, no shared concern.
 * `resetDefaultLabels()`'s auto-triggered (readonly-gated) label regen is exactly
 * `maybeRegenerateDefaultLabels()`'s existing `manualReset: false` behavior, reused as-is rather
 * than re-implemented.
 *
 * Healthcheck (Phase 72 — HTTP/CMD healthcheck type, method/scheme/host/port/path/return-code/
 * response-text, timing fields, and the enable/disable toggle). This tab was the last consumer
 * of `App\Livewire\Project\Shared\HealthChecks`, a generic `$resource`-typed component Database
 * used to share too before Phase 60 gave databases their own narrower inline version (no HTTP
 * fields — a database has no HTTP endpoint to probe). `submit()`/`instantSave()` were identical
 * full-form saves in the original, ported as one `updateHealthcheck()` endpoint.
 *
 * Servers (Phase 73 — multi-server deployment management: the primary/additional-server cards,
 * per-server Deploy/Promote-to-Primary/Stop/Remove actions, and the "add another server" picker).
 * The last consumer of `App\Livewire\Project\Shared\Destination`, generic-`$resource`-typed but
 * never actually used by Database or Service. Password-confirmed remove mirrors
 * `ManagesResourceDanger::destroyResource()`'s `verifyPasswordConfirmation()` contract.
 *
 * Git Source (Phase 74 — the git repository/branch/commit form, deploy-key management, and
 * Change Git Source picker; `changeSource()` genuinely touches GitHub's API to resolve the
 * repository's numeric project id, carrying the standard untested-happy-path gap). This is the
 * router's last tab: `Application\Configuration` is now **fully retired from Livewire**,
 * matching `Service\Configuration` (Phase 59) and `Database\Configuration` (Phase 62)'s own
 * precedent. The shell itself (`App\Livewire\Project\Application\Configuration`) and two more
 * classes that had quietly become orphaned along the way — `Heading` (superseded by
 * `ApplicationHeading.jsx` back in Phase 64, but the Livewire shell kept rendering the old one
 * for whichever tabs hadn't converted yet) and `ServerStatusBadge` (a sidebar nav decoration
 * with no other consumer) — are deleted in this same phase alongside `Source` itself.
 *
 * Environment Variables' preview-deployment set, build secrets, and sort-alphabetically toggle
 * stay deferred — see ManagesResourceEnvironmentVariables' docblock. These are the only pieces
 * of Application-resource functionality from the original 16-tab router not yet ported anywhere.
 */
class ProjectApplicationConfigurationController extends Controller
{
    use AuthorizesRequests;
    use ManagesApplicationHeading;
    use ManagesResourceDanger;
    use ManagesResourceEnvironmentVariables;
    use ManagesResourceLimits;
    use ManagesResourceOperations;
    use ManagesResourceScheduledTasks;
    use ManagesResourceStorages;
    use ManagesResourceTags;
    use ManagesResourceWebhooks;
    use ResolvesProjectResources;

    public function show(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): Response|RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('view', $application);

        $tab = str((string) $request->route()->getName())->after('project.application.')->before('.show')->value();
        $parameters = compact('project_uuid', 'environment_uuid', 'application_uuid');

        $props = [
            'tab' => $tab,
            ...$this->applicationHeadingProps($application, $parameters),
            'parameters' => $parameters,
            'canUpdate' => auth()->user()->can('update', $application),
            'tabs' => $this->tabLinks($application, $parameters),
        ];

        return Inertia::render('Project/Application/Configuration', [...$props, ...$this->tabProps($tab, $application, $parameters)]);
    }

    public function storeTag(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->storeResourceTag($request, $application);
    }

    public function destroyTag(string $project_uuid, string $environment_uuid, string $application_uuid, string $tag_id): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->destroyResourceTag($application, $tag_id);
    }

    public function updateResourceLimits(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->applyResourceLimitsUpdate($request, $application);
    }

    public function move(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->moveResourceToEnvironment($request, $application, 'project.application.configuration', compact('project_uuid', 'environment_uuid', 'application_uuid'), 'application_uuid');
    }

    /** Port of the application branch of Project\Shared\ResourceOperations::cloneTo(), delegating to clone_application(). */
    public function clone(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $validated = Validator::make($request->all(), [
            'destination_id' => 'required|integer',
            'clone_volume_data' => 'boolean',
        ])->validate();

        $newDestination = StandaloneDocker::ownedByCurrentTeam()->find($validated['destination_id'])
            ?? SwarmDocker::ownedByCurrentTeam()->find($validated['destination_id']);
        if (! $newDestination) {
            return back()->withErrors(['destination_id' => 'Destination not found.']);
        }

        $uuid = (string) new Cuid2;
        $clone = clone_application($application, $newDestination, ['uuid' => $uuid], (bool) ($validated['clone_volume_data'] ?? false));

        return redirect()->to(route('project.application.configuration', [
            'project_uuid' => $project_uuid,
            'environment_uuid' => $environment_uuid,
            'application_uuid' => $clone->uuid,
        ]).'#resource-operations');
    }

    public function destroy(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->destroyResource($request, $application, compact('project_uuid', 'environment_uuid'));
    }

    public function scheduledTaskStore(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->storeScheduledTask($request, $application);
    }

    public function scheduledTaskUpdate(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->updateScheduledTask($request, $application, $this->resolveOwnedScheduledTask($application, (string) $request->route('task_uuid')));
    }

    public function scheduledTaskToggle(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->toggleScheduledTask($application, $this->resolveOwnedScheduledTask($application, (string) $request->route('task_uuid')));
    }

    public function scheduledTaskExecute(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->executeScheduledTaskNow($application, $this->resolveOwnedScheduledTask($application, (string) $request->route('task_uuid')));
    }

    public function scheduledTaskDestroy(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        $parameters = compact('project_uuid', 'environment_uuid', 'application_uuid');

        return $this->destroyScheduledTask($application, $this->resolveOwnedScheduledTask($application, (string) $request->route('task_uuid')), 'project.application', $parameters);
    }

    public function scheduledTaskDownload(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): StreamedResponse|RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('view', $application);

        return $this->downloadScheduledTaskLogs(
            $this->resolveOwnedScheduledTask($application, (string) $request->route('task_uuid')),
            (int) $request->route('execution_id'),
        );
    }

    public function storeEnv(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->envStore($request, $application);
    }

    public function updateEnv(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid, string $env_id): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->envUpdate($request, $application, $env_id);
    }

    public function lockEnv(string $project_uuid, string $environment_uuid, string $application_uuid, string $env_id): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->envLock($application, $env_id);
    }

    public function destroyEnv(string $project_uuid, string $environment_uuid, string $application_uuid, string $env_id): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->envDestroy($application, $env_id);
    }

    public function bulkUpdateEnvs(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->envBulkUpdate($request, $application);
    }

    public function storagesVolumeStore(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->storeStorageVolume($request, $application);
    }

    public function storagesFileStore(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->storeStorageFile($request, $application);
    }

    public function storagesDirectoryStore(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->storeStorageDirectory($request, $application);
    }

    public function storagesVolumeUpdate(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid, string $volume_id): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->updateStorageVolume($request, $application, $this->resolveOwnedVolume($application, $volume_id));
    }

    public function storagesVolumeDestroy(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid, string $volume_id): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->destroyStorageVolume($request, $application, $this->resolveOwnedVolume($application, $volume_id));
    }

    public function storagesFileUpdate(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid, string $file_id): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->updateStorageFile($request, $application, $this->resolveOwnedFileVolume($application, $file_id));
    }

    public function storagesFileLoad(string $project_uuid, string $environment_uuid, string $application_uuid, string $file_id): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->loadStorageFile($application, $this->resolveOwnedFileVolume($application, $file_id));
    }

    public function storagesFileConvert(string $project_uuid, string $environment_uuid, string $application_uuid, string $file_id): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->convertStorageFile($application, $this->resolveOwnedFileVolume($application, $file_id));
    }

    public function storagesFileDestroy(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid, string $file_id): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->destroyStorageFile($request, $application, $this->resolveOwnedFileVolume($application, $file_id));
    }

    public function updateWebhookSecrets(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->updateManualWebhookSecrets($request, $application);
    }

    public function swarmUpdate(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $validated = Validator::make($request->all(), [
            'swarmReplicas' => 'required|integer|min:0',
            'swarmPlacementConstraints' => 'nullable|string',
            'isSwarmOnlyWorkerNodes' => 'required|boolean',
        ])->validate();

        $application->swarm_replicas = $validated['swarmReplicas'];
        $application->swarm_placement_constraints = filled($validated['swarmPlacementConstraints'] ?? null)
            ? base64_encode($validated['swarmPlacementConstraints'])
            : null;
        $application->save();

        $application->settings->is_swarm_only_worker_nodes = $validated['isSwarmOnlyWorkerNodes'];
        $application->settings->save();

        return back()->with('success', 'Swarm settings updated.');
    }

    public function rollbackSaveSettings(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $validated = Validator::make($request->all(), [
            'dockerImagesToKeep' => 'integer|min:0|max:100',
        ])->validate();

        $application->settings->docker_images_to_keep = $validated['dockerImagesToKeep'];
        $application->settings->save();

        return back()->with('success', 'Settings saved.');
    }

    public function rollbackLoadImages(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('view', $application);

        $current = null;
        $images = [];
        $server = $application->destination->server;
        if ($server->isFunctional()) {
            $image = $application->docker_registry_image_name ?? $application->uuid;
            $output = instant_remote_process([
                "docker inspect --format='{{.Config.Image}}' {$application->uuid}",
            ], $server, throwError: false);
            $current = data_get(str($output)->trim()->explode(':'), 1);

            $output = instant_remote_process([
                "docker images --format '{{.Repository}}#{{.Tag}}#{{.CreatedAt}}'",
            ], $server);
            $images = str($output)->trim()->explode("\n")->filter(fn ($item) => str($item)->contains($image))
                ->map(function ($item) use ($current) {
                    $item = str($item)->explode('#');

                    return [
                        'tag' => $item[1],
                        'createdAt' => $item[2],
                        'isCurrent' => $item[1] === $current,
                    ];
                })->values()->toArray();
        }

        return back()->with([
            'rollbackImages' => $images,
            'rollbackCurrentTag' => $current,
        ] + ($request->boolean('showToast') ? ['success' => 'Images loaded.'] : []));
    }

    public function rollbackDeploy(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('deploy', $application);

        $validated = Validator::make($request->all(), [
            'tag' => 'required|string',
        ])->validate();

        try {
            $commit = validateGitRef($validated['tag'], 'rollback commit');
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in rollbackDeploy().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }

        $deploymentUuid = (string) new Cuid2;
        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: $deploymentUuid,
            commit: $commit,
            rollback: true,
            force_rebuild: false,
        );

        if ($result['status'] === 'queue_full') {
            return back()->with('error', $result['message']);
        }

        return redirect()->route('project.application.deployment.show', [
            'project_uuid' => $project_uuid,
            'environment_uuid' => $environment_uuid,
            'application_uuid' => $application_uuid,
            'deployment_uuid' => $deploymentUuid,
        ]);
    }

    /** Port of Advanced::instantSave() — the checkbox-triggered whole-form save. */
    public function instantSaveAdvanced(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $validated = Validator::make($request->all(), $this->advancedValidationRules())->validate();

        if ($validated['isLogDrainEnabled'] && ! $application->destination->server->isLogDrainEnabled()) {
            $validated['isLogDrainEnabled'] = false;
            $this->syncAdvancedFieldsToModel($application, $validated);

            return back()->with('error', 'Log drain is not enabled on this server.');
        }

        $reset = $application->isForceHttpsEnabled() !== $validated['isForceHttpsEnabled']
            || $application->isGzipEnabled() !== $validated['isGzipEnabled']
            || $application->isStripprefixEnabled() !== $validated['isStripprefixEnabled'];

        if ($application->settings->is_raw_compose_deployment_enabled) {
            $application->oldRawParser();
        } else {
            $application->parse();
        }
        $this->syncAdvancedFieldsToModel($application, $validated);

        if ($reset) {
            $this->maybeRegenerateDefaultLabels($application);
        }

        return back()->with('success', 'Settings saved.');
    }

    /** Port of Advanced::submit() — the GPU section's own Save button. */
    public function updateAdvanced(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $validated = Validator::make($request->all(), $this->advancedValidationRules())->validate();

        if (filled($validated['gpuCount'] ?? null) && filled($validated['gpuDeviceIds'] ?? null)) {
            return back()->with('error', 'You cannot set both GPU count and GPU device IDs.');
        }

        $this->syncAdvancedFieldsToModel($application, $validated);

        return back()->with('success', 'Settings saved.');
    }

    /** Port of Advanced::saveCustomName() — slugifies, then rejects a clash with another application on the same server. */
    public function saveAdvancedCustomName(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $customInternalName = str($request->string('customInternalName')->value())->isNotEmpty()
            ? str($request->string('customInternalName')->value())->slug()->value()
            : null;

        if ($customInternalName !== null) {
            $server = $application->destination->server;
            $clash = $server->applications()->contains(fn ($other) => $other->id !== $application->id && $other->settings->custom_internal_name === $customInternalName);
            if ($clash) {
                return back()->with('error', 'This custom container name is already in use by another application on this server.');
            }
        }

        $application->settings->custom_internal_name = $customInternalName;
        $application->settings->save();

        return back()->with('success', 'Custom name saved.');
    }

    /** Port of Advanced::saveStopGracePeriod(). */
    public function saveAdvancedStopGracePeriod(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $stopGracePeriod = $request->string('stopGracePeriod')->value();
        $validated = Validator::make(
            ['stopGracePeriod' => $stopGracePeriod === '' ? null : $stopGracePeriod],
            ['stopGracePeriod' => ['nullable', 'integer', 'min:'.MIN_STOP_GRACE_PERIOD_SECONDS, 'max:'.MAX_STOP_GRACE_PERIOD_SECONDS]],
            [],
            ['stopGracePeriod' => 'stop grace period']
        )->validate();

        $application->settings->stop_grace_period = $validated['stopGracePeriod'] === null ? null : (int) $validated['stopGracePeriod'];
        $application->settings->save();

        return back()->with('success', 'Stop grace period updated.');
    }

    /** Port of Advanced::saveMaxRestartCount(). */
    public function saveAdvancedMaxRestartCount(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $validated = Validator::make($request->all(), [
            'maxRestartCount' => 'integer|min:0',
        ])->validate();

        $application->max_restart_count = $validated['maxRestartCount'];
        $application->save();

        return back()->with('success', 'Max restart count saved.');
    }

    /** Port of Shared\HealthChecks::submit()/instantSave() — a single whole-form save, matching this migration's established instant-save pattern. */
    public function updateHealthcheck(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $validated = Validator::make($request->all(), $this->healthcheckValidationRules())->validate();
        $this->syncHealthcheckFieldsToModel($application, $validated);

        return back()->with('success', 'Health check updated.');
    }

    /** Port of Shared\HealthChecks::toggleHealthcheck(). */
    public function toggleHealthcheckEnabled(string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $wasEnabled = (bool) $application->health_check_enabled;
        $application->health_check_enabled = ! $wasEnabled;
        $application->save();

        if ($application->health_check_enabled && ! $wasEnabled && $application->isRunning()) {
            return back()->with('info', 'Health check has been enabled. A restart is required to apply the new settings.');
        }

        return back()->with('success', 'Health check '.($application->health_check_enabled ? 'enabled' : 'disabled').'.');
    }

    /** Port of Shared\Destination::redeploy() — deploy to one specific server/network only. */
    public function serversRedeploy(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('deploy', $application);

        $validated = Validator::make($request->all(), [
            'networkId' => 'required|integer',
            'serverId' => 'required|integer',
        ])->validate();

        if ($application->additional_servers->count() > 0 && blank($application->docker_registry_image_name)) {
            return back()->with('error', 'Before deploying to multiple servers, you must first set a Docker image in the General tab.');
        }

        $server = Server::ownedByCurrentTeam()->findOrFail($validated['serverId']);
        $destination = $server->standaloneDockers->where('id', $validated['networkId'])->firstOrFail();
        $deploymentUuid = (string) new Cuid2;
        $result = queue_application_deployment(
            deployment_uuid: $deploymentUuid,
            application: $application,
            server: $server,
            destination: $destination,
            only_this_server: true,
            no_questions_asked: true,
        );

        if ($result['status'] === 'queue_full') {
            return back()->with('error', $result['message']);
        }
        if ($result['status'] === 'skipped') {
            return back()->with('success', $result['message']);
        }

        return redirect()->route('project.application.deployment.show', [
            'project_uuid' => $project_uuid,
            'environment_uuid' => $environment_uuid,
            'application_uuid' => $application_uuid,
            'deployment_uuid' => $deploymentUuid,
        ]);
    }

    /** Port of Shared\Destination::stop(). */
    public function serversStop(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('deploy', $application);

        $validated = Validator::make($request->all(), [
            'serverId' => 'required|integer',
        ])->validate();

        $server = Server::ownedByCurrentTeam()->findOrFail($validated['serverId']);
        $error = StopApplicationOneServer::run($application, $server);
        GetContainersStatus::run($application->destination->server);

        if ($error) {
            return back()->with('error', $error);
        }

        return back()->with('success', 'Application stopped.');
    }

    /** Port of Shared\Destination::promote() — swaps the primary destination with an additional one. */
    public function serversPromote(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $validated = Validator::make($request->all(), [
            'networkId' => 'required|integer',
            'serverId' => 'required|integer',
        ])->validate();

        $server = Server::ownedByCurrentTeam()->findOrFail($validated['serverId']);
        $network = StandaloneDocker::ownedByCurrentTeam()->where('server_id', $server->id)->findOrFail($validated['networkId']);

        $application->getConnection()->transaction(function () use ($application, $network, $server) {
            $mainDestination = $application->destination;
            $application->update([
                'destination_id' => $network->id,
                'destination_type' => StandaloneDocker::class,
            ]);
            $application->additional_networks()->wherePivot('server_id', $server->id)->detach($network->id);
            $application->additional_networks()->attach($mainDestination->id, ['server_id' => $mainDestination->server->id]);
        });
        $application->refresh();
        GetContainersStatus::run($application->destination->server);

        return back()->with('success', 'Server promoted to primary.');
    }

    /** Port of Shared\Destination::addServer(). */
    public function serversAdd(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $validated = Validator::make($request->all(), [
            'networkId' => 'required|integer',
            'serverId' => 'required|integer',
        ])->validate();

        $server = Server::ownedByCurrentTeam()->findOrFail($validated['serverId']);
        $network = StandaloneDocker::ownedByCurrentTeam()->where('server_id', $server->id)->findOrFail($validated['networkId']);

        $application->additional_networks()->attach($network->id, ['server_id' => $server->id]);

        return back()->with('success', 'Server added.');
    }

    /** Port of Shared\Destination::removeServer() — password-confirmed, matching ManagesResourceDanger's destroyResource() contract. */
    public function serversRemove(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $validated = Validator::make($request->all(), [
            'networkId' => 'required|integer',
            'serverId' => 'required|integer',
            'password' => 'required|string',
        ])->validate();

        if (! verifyPasswordConfirmation($validated['password'])) {
            return back()->with('error', 'The provided password is incorrect.');
        }

        if ($application->destination->server->id == $validated['serverId'] && $application->destination->id == $validated['networkId']) {
            return back()->with('error', 'You are trying to remove the main server.');
        }

        $server = Server::ownedByCurrentTeam()->findOrFail($validated['serverId']);
        StopApplicationOneServer::run($application, $server);
        $application->additional_networks()->wherePivot('server_id', $validated['serverId'])->detach($validated['networkId']);
        ApplicationStatusChanged::dispatch(data_get($application, 'environment.project.team.id'));

        return back()->with('success', 'Server removed.');
    }

    /** Port of Application\Source::submit() — the git repository/branch/commit form. */
    public function updateSource(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $validated = Validator::make($request->all(), [
            'gitRepository' => 'required|string',
            'gitBranch' => ['required', 'string', new ValidGitBranch],
            'gitCommitSha' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._\-\/]*$/'],
        ])->validate();

        $gitCommitSha = filled($validated['gitCommitSha'] ?? null) ? trim($validated['gitCommitSha']) : 'HEAD';

        $application->update([
            'git_repository' => trim($validated['gitRepository']),
            'git_branch' => trim($validated['gitBranch']),
            'git_commit_sha' => $gitCommitSha,
        ]);

        return back()->with('success', 'Application source updated!');
    }

    /** Port of Application\Source::setPrivateKey(). */
    public function setSourcePrivateKey(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $validated = Validator::make($request->all(), [
            'privateKeyId' => 'required|integer',
        ])->validate();

        $key = PrivateKey::ownedByCurrentTeam()->findOrFail($validated['privateKeyId']);
        $application->update(['private_key_id' => $key->id]);

        return back()->with('success', 'Private key updated!');
    }

    /**
     * Port of Application\Source::changeSource() — genuinely touches GitHub's API
     * (`githubApi()`) to resolve the repository's numeric project id, carrying the standard
     * untested-happy-path gap (docs/smoketest.md).
     */
    public function changeSource(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $validated = Validator::make($request->all(), [
            'sourceId' => 'required|integer',
            'sourceType' => ['required', 'string', 'in:'.GithubApp::class.','.GitlabApp::class],
        ])->validate();

        $sourceType = $validated['sourceType'];
        $source = $sourceType::ownedByCurrentTeam()->findOrFail($validated['sourceId']);
        $application->update([
            'source_id' => $source->id,
            'source_type' => $sourceType,
        ]);

        try {
            ['repository' => $customRepository] = $application->customRepository();
            $repository = githubApi($application->source, "repos/{$customRepository}");
            $repositoryProjectId = data_get($repository, 'data.id');
            if (isset($repositoryProjectId) && $application->repository_project_id !== $repositoryProjectId) {
                $application->repository_project_id = $repositoryProjectId;
                $application->save();
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in changeSource().', ['error' => $e->getMessage()]);
            // The source itself is already switched; a failed project-id lookup isn't fatal.
        }

        return back()->with('success', 'Source updated!');
    }

    /**
     * @return array<string, mixed>
     */
    private function healthcheckValidationRules(): array
    {
        return [
            'healthCheckEnabled' => 'boolean',
            'healthCheckType' => 'string|in:http,cmd',
            'healthCheckCommand' => ['nullable', 'required_if:healthCheckType,cmd', 'string', 'max:1000', 'regex:/^[a-zA-Z0-9 \-_.\/:=@,+]+$/'],
            'healthCheckMethod' => 'required|string|in:GET,HEAD,POST,OPTIONS',
            'healthCheckScheme' => 'required|string|in:http,https',
            'healthCheckHost' => ['required', 'string', 'regex:/^[a-zA-Z0-9.\-_]+$/'],
            'healthCheckPort' => 'nullable|integer|min:1|max:65535',
            'healthCheckPath' => ['required', 'string', 'regex:#^[a-zA-Z0-9/\-_.~%,;]+$#'],
            'healthCheckReturnCode' => 'integer',
            'healthCheckResponseText' => 'nullable|string',
            'healthCheckInterval' => 'integer|min:1',
            'healthCheckTimeout' => 'integer|min:1',
            'healthCheckRetries' => 'integer|min:1',
            'healthCheckStartPeriod' => 'integer',
            'customHealthcheckFound' => 'boolean',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function syncHealthcheckFieldsToModel(Application $application, array $validated): void
    {
        $application->health_check_enabled = (bool) ($validated['healthCheckEnabled'] ?? false);
        $application->health_check_type = $validated['healthCheckType'] ?? 'http';
        $application->health_check_command = $validated['healthCheckCommand'] ?? null;
        $application->health_check_method = $validated['healthCheckMethod'];
        $application->health_check_scheme = $validated['healthCheckScheme'];
        $application->health_check_host = $validated['healthCheckHost'];
        $application->health_check_port = $validated['healthCheckPort'] ?? null;
        $application->health_check_path = $validated['healthCheckPath'];
        $application->health_check_return_code = $validated['healthCheckReturnCode'];
        $application->health_check_response_text = $validated['healthCheckResponseText'] ?? null;
        $application->health_check_interval = $validated['healthCheckInterval'];
        $application->health_check_timeout = $validated['healthCheckTimeout'];
        $application->health_check_retries = $validated['healthCheckRetries'];
        $application->health_check_start_period = $validated['healthCheckStartPeriod'];
        $application->custom_healthcheck_found = (bool) ($validated['customHealthcheckFound'] ?? false);
        $application->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function advancedValidationRules(): array
    {
        return [
            'isForceHttpsEnabled' => 'boolean',
            'isGitSubmodulesEnabled' => 'boolean',
            'isGitLfsEnabled' => 'boolean',
            'isGitShallowCloneEnabled' => 'boolean',
            'isPreviewDeploymentsEnabled' => 'boolean',
            'isPrDeploymentsPublicEnabled' => 'boolean',
            'isAutoDeployEnabled' => 'boolean',
            'disableBuildCache' => 'boolean',
            'injectBuildArgsToDockerfile' => 'boolean',
            'includeSourceCommitInBuild' => 'boolean',
            'isLogDrainEnabled' => 'boolean',
            'isGpuEnabled' => 'boolean',
            'gpuDriver' => 'nullable|string',
            'gpuCount' => 'nullable|string',
            'gpuDeviceIds' => 'nullable|string',
            'gpuOptions' => 'nullable|string',
            'isBuildServerEnabled' => 'boolean',
            'isConsistentContainerNameEnabled' => 'boolean',
            'isGzipEnabled' => 'boolean',
            'isStripprefixEnabled' => 'boolean',
            'isRawComposeDeploymentEnabled' => 'boolean',
            'isConnectToDockerNetworkEnabled' => 'boolean',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function syncAdvancedFieldsToModel(Application $application, array $validated): void
    {
        $application->settings->is_force_https_enabled = (bool) ($validated['isForceHttpsEnabled'] ?? false);
        $application->settings->is_git_submodules_enabled = (bool) ($validated['isGitSubmodulesEnabled'] ?? false);
        $application->settings->is_git_lfs_enabled = (bool) ($validated['isGitLfsEnabled'] ?? false);
        $application->settings->is_git_shallow_clone_enabled = (bool) ($validated['isGitShallowCloneEnabled'] ?? false);
        $application->settings->is_preview_deployments_enabled = (bool) ($validated['isPreviewDeploymentsEnabled'] ?? false);
        $application->settings->is_pr_deployments_public_enabled = (bool) ($validated['isPrDeploymentsPublicEnabled'] ?? false);
        $application->settings->is_auto_deploy_enabled = (bool) ($validated['isAutoDeployEnabled'] ?? false);
        $application->settings->is_log_drain_enabled = (bool) ($validated['isLogDrainEnabled'] ?? false);
        $application->settings->is_gpu_enabled = (bool) ($validated['isGpuEnabled'] ?? false);
        $application->settings->gpu_driver = $validated['gpuDriver'] ?? '';
        $application->settings->gpu_count = $validated['gpuCount'] ?? null;
        $application->settings->gpu_device_ids = $validated['gpuDeviceIds'] ?? null;
        $application->settings->gpu_options = $validated['gpuOptions'] ?? null;
        $application->settings->is_build_server_enabled = (bool) ($validated['isBuildServerEnabled'] ?? false);
        $application->settings->is_consistent_container_name_enabled = (bool) ($validated['isConsistentContainerNameEnabled'] ?? false);
        $application->settings->is_gzip_enabled = (bool) ($validated['isGzipEnabled'] ?? false);
        $application->settings->is_stripprefix_enabled = (bool) ($validated['isStripprefixEnabled'] ?? false);
        $application->settings->is_raw_compose_deployment_enabled = (bool) ($validated['isRawComposeDeploymentEnabled'] ?? false);
        $application->settings->connect_to_docker_network = (bool) ($validated['isConnectToDockerNetworkEnabled'] ?? false);
        $application->settings->disable_build_cache = (bool) ($validated['disableBuildCache'] ?? false);
        $application->settings->inject_build_args_to_dockerfile = (bool) ($validated['injectBuildArgsToDockerfile'] ?? false);
        $application->settings->include_source_commit_in_build = (bool) ($validated['includeSourceCommitInBuild'] ?? false);
        $application->settings->save();
    }

    /** Port of Project\Application\Preview\Form::submit()/resetToDefault() — one endpoint, `reset` flag picks the branch. */
    public function updatePreviewUrlTemplate(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        if ($request->boolean('reset')) {
            $application->preview_url_template = '{{pr_id}}.{{domain}}';
        } else {
            $validated = Validator::make($request->all(), [
                'previewUrlTemplate' => 'required|string',
            ])->validate();
            $application->preview_url_template = str_replace(' ', '', $validated['previewUrlTemplate']);
        }
        $application->save();

        return back()->with('success', 'Preview url template updated.');
    }

    /** Port of Previews::load_prs() — a real GitHub API call, carries the same untested-happy-path gap as SSH-touching actions. */
    public function loadPullRequests(string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        try {
            ['rate_limit_remaining' => $rateLimitRemaining, 'data' => $data] = githubApi(source: $application->source, endpoint: "/repos/{$application->git_repository}/pulls");

            return back()->with([
                'pullRequests' => $data->sortBy('number')->values()->map(fn ($pr) => [
                    'number' => data_get($pr, 'number'),
                    'title' => data_get($pr, 'title'),
                    'htmlUrl' => data_get($pr, 'html_url'),
                ])->toArray(),
                'rateLimitRemaining' => $rateLimitRemaining,
            ]);
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in loadPullRequests().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }
    }

    /** Port of Previews::add() — creates (or reuses) the preview row, without deploying. */
    public function addPreview(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $validated = Validator::make($request->all(), [
            'pullRequestId' => 'required|integer',
            'pullRequestHtmlUrl' => 'nullable|string',
            'dockerRegistryImageTag' => 'nullable|string',
        ])->validate();

        $this->addPreviewToModel($application, (int) $validated['pullRequestId'], $validated['pullRequestHtmlUrl'] ?? null, $validated['dockerRegistryImageTag'] ?? null);

        return back()->with('success', 'Preview added.');
    }

    /** Port of Previews::add_and_deploy() — also the manual Docker-image-preview form's target. */
    public function addAndDeployPreview(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('deploy', $application);

        $validated = Validator::make($request->all(), [
            'pullRequestId' => 'required|integer|min:1',
            'pullRequestHtmlUrl' => 'nullable|string',
            'dockerRegistryImageTag' => 'nullable|string',
        ])->validate();

        if ($application->build_pack === 'dockerimage' && blank($validated['pullRequestHtmlUrl'] ?? null) && blank($validated['dockerRegistryImageTag'] ?? null)) {
            return back()->with('error', 'Both pull request id and docker tag are required.');
        }

        $this->addPreviewToModel($application, (int) $validated['pullRequestId'], $validated['pullRequestHtmlUrl'] ?? null, $validated['dockerRegistryImageTag'] ?? null);

        return $this->deployPreviewInternal($application, $project_uuid, $environment_uuid, $application_uuid, (int) $validated['pullRequestId'], $validated['pullRequestHtmlUrl'] ?? null, forceRebuild: false, dockerRegistryImageTag: $validated['dockerRegistryImageTag'] ?? null);
    }

    /** Port of Previews::deploy() — redeploy/deploy an already-added preview. */
    public function deployPreview(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid, string $pull_request_id): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('deploy', $application);

        return $this->deployPreviewInternal($application, $project_uuid, $environment_uuid, $application_uuid, (int) $pull_request_id, null, forceRebuild: false, dockerRegistryImageTag: $request->string('dockerRegistryImageTag')->value() ?: null);
    }

    /** Port of Previews::force_deploy_without_cache(). */
    public function forceDeployPreviewWithoutCache(string $project_uuid, string $environment_uuid, string $application_uuid, string $pull_request_id): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('deploy', $application);

        $dockerRegistryImageTag = null;
        if ($application->build_pack === 'dockerimage') {
            $dockerRegistryImageTag = $application->previews()->where('pull_request_id', (int) $pull_request_id)->value('docker_registry_image_tag');
        }

        return $this->deployPreviewInternal($application, $project_uuid, $environment_uuid, $application_uuid, (int) $pull_request_id, null, forceRebuild: true, dockerRegistryImageTag: $dockerRegistryImageTag);
    }

    /**
     * Port of the shared core of Previews::add()/add_and_deploy()/deploy() — finds or creates the
     * preview row for a pull request, applying the docker-image-tag update the original repeats
     * at each of those three call sites.
     */
    private function addPreviewToModel(Application $application, int $pullRequestId, ?string $pullRequestHtmlUrl, ?string $dockerRegistryImageTag): ?ApplicationPreview
    {
        $found = ApplicationPreview::where('application_id', $application->id)->where('pull_request_id', $pullRequestId)->first();

        if ($application->build_pack === 'dockercompose') {
            if (! $found && filled($pullRequestHtmlUrl)) {
                $found = ApplicationPreview::create([
                    'application_id' => $application->id,
                    'pull_request_id' => $pullRequestId,
                    'pull_request_html_url' => $pullRequestHtmlUrl,
                    'docker_compose_domains' => $application->docker_compose_domains,
                ]);
            }
            $found?->generate_preview_fqdn_compose();
        } else {
            if (! $found && (filled($pullRequestHtmlUrl) || ($application->build_pack === 'dockerimage' && filled($dockerRegistryImageTag)))) {
                $found = ApplicationPreview::create([
                    'application_id' => $application->id,
                    'pull_request_id' => $pullRequestId,
                    'pull_request_html_url' => $pullRequestHtmlUrl ?? '',
                    'docker_registry_image_tag' => $dockerRegistryImageTag,
                ]);
            }
            if ($found && $application->build_pack === 'dockerimage' && filled($dockerRegistryImageTag)) {
                $found->docker_registry_image_tag = $dockerRegistryImageTag;
                $found->save();
            }
            $found?->generate_preview_fqdn();
        }
        $application->refresh();

        return $found;
    }

    /** Port of the shared core of Previews::deploy(). */
    private function deployPreviewInternal(Application $application, string $project_uuid, string $environment_uuid, string $application_uuid, int $pullRequestId, ?string $pullRequestHtmlUrl, bool $forceRebuild, ?string $dockerRegistryImageTag): RedirectResponse
    {
        try {
            $found = ApplicationPreview::where('application_id', $application->id)->where('pull_request_id', $pullRequestId)->first();
            if (! $found && (filled($pullRequestHtmlUrl) || ($application->build_pack === 'dockerimage' && filled($dockerRegistryImageTag)))) {
                $found = ApplicationPreview::create([
                    'application_id' => $application->id,
                    'pull_request_id' => $pullRequestId,
                    'pull_request_html_url' => $pullRequestHtmlUrl ?? '',
                    'docker_registry_image_tag' => $dockerRegistryImageTag,
                ]);
            }
            if ($found && $application->build_pack === 'dockerimage' && filled($dockerRegistryImageTag)) {
                $found->docker_registry_image_tag = $dockerRegistryImageTag;
                $found->save();
            }

            $deploymentUuid = (string) new Cuid2;
            $result = queue_application_deployment(
                application: $application,
                deployment_uuid: $deploymentUuid,
                force_rebuild: $forceRebuild,
                pull_request_id: $pullRequestId,
                git_type: $found->git_type ?? null,
                docker_registry_image_tag: $dockerRegistryImageTag,
            );

            if ($result['status'] === 'queue_full') {
                return back()->with('error', $result['message']);
            }
            if ($result['status'] === 'skipped') {
                return back()->with('success', $result['message']);
            }

            return redirect()->route('project.application.deployment.show', [
                'project_uuid' => $project_uuid,
                'environment_uuid' => $environment_uuid,
                'application_uuid' => $application_uuid,
                'deployment_uuid' => $deploymentUuid,
            ]);
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in deployPreviewInternal().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }
    }

    /** Port of Previews::save_preview() — non-compose domain (+ dockerimage tag) save, with DNS validation and the domain-conflict flash flow. */
    public function savePreviewDomain(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid, string $pull_request_id): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $preview = $application->previews->firstWhere('pull_request_id', $pull_request_id);
        if (! $preview) {
            return back()->with('error', 'Preview not found.');
        }

        $validated = Validator::make($request->all(), [
            'fqdn' => 'nullable|string',
            'dockerRegistryImageTag' => 'nullable|string',
        ])->validate();

        $success = true;
        $fqdn = trim((string) ($validated['fqdn'] ?? ''));
        if (filled($fqdn)) {
            $fqdn = str($fqdn)->replaceEnd(',', '')->trim();
            $fqdn = $fqdn->replaceStart(',', '')->trim();
            $fqdn = $fqdn->trim()->lower()->toString();

            if (! validateDNSEntry($fqdn, $application->destination->server)) {
                $success = false;
            }

            if (! $request->boolean('force_save_domains')) {
                $result = checkDomainUsage(resource: $application, domain: $fqdn);
                if ($result['hasConflicts']) {
                    return back()->with(['domainConflicts' => $result['conflicts'], 'showDomainConflictModal' => true]);
                }
            }
        }

        if ($success) {
            $preview->fqdn = $fqdn ?: null;
            if ($application->build_pack === 'dockerimage') {
                $preview->docker_registry_image_tag = $validated['dockerRegistryImageTag'] ?? null;
            }
            $preview->save();

            return back()->with('success', 'Preview saved.<br><br>Do not forget to redeploy the preview to apply the changes.');
        }

        return back()->with('error', 'Validating DNS failed. Make sure you have added the DNS records correctly.');
    }

    /** Port of Previews::generate_preview() — branches on build pack, matching the original. */
    public function generatePreviewDomain(string $project_uuid, string $environment_uuid, string $application_uuid, string $pull_request_id): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $preview = $application->previews->firstWhere('pull_request_id', $pull_request_id);
        if (! $preview) {
            return back()->with('error', 'Preview not found.');
        }

        if ($application->build_pack === 'dockercompose') {
            $preview->generate_preview_fqdn_compose();
        } else {
            $preview->generate_preview_fqdn();
        }
        $application->refresh();

        return back()->with('success', 'Domain generated.');
    }

    /** Port of Previews\PreviewsCompose::save() — per-compose-service preview domain. */
    public function savePreviewComposeDomain(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid, string $pull_request_id): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $preview = $application->previews->firstWhere('pull_request_id', $pull_request_id);
        if (! $preview) {
            return back()->with('error', 'Preview not found.');
        }

        $validated = Validator::make($request->all(), [
            'serviceName' => 'required|string',
            'domain' => 'nullable|string',
        ])->validate();

        $domains = json_decode((string) $preview->docker_compose_domains, true) ?: [];
        $domains[$validated['serviceName']] = $domains[$validated['serviceName']] ?? [];
        $domains[$validated['serviceName']]['domain'] = $validated['domain'] ?? null;
        $preview->docker_compose_domains = json_encode($domains);
        $preview->save();

        return back()->with('success', 'Domain saved.');
    }

    /** Port of Previews\PreviewsCompose::generate(). */
    public function generatePreviewComposeDomain(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid, string $pull_request_id): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $preview = $application->previews->firstWhere('pull_request_id', $pull_request_id);
        if (! $preview) {
            return back()->with('error', 'Preview not found.');
        }

        $validated = Validator::make($request->all(), [
            'serviceName' => 'required|string',
        ])->validate();
        $serviceName = $validated['serviceName'];

        $applicationDomains = collect(json_decode((string) $application->docker_compose_domains, true) ?: []);
        $domainString = data_get($applicationDomains->get($serviceName), 'domain');

        if (blank($domainString)) {
            $server = $application->destination->server;
            $template = $application->preview_url_template;
            $random = (string) new Cuid2;
            $generatedFqdn = generateUrl(server: $server, random: $random);
            $previewFqdn = str_replace('{{random}}', $random, $template);
            $previewFqdn = str_replace('{{domain}}', str($generatedFqdn)->after('://')->value(), $previewFqdn);
            $previewFqdn = str_replace('{{pr_id}}', (string) $pull_request_id, $previewFqdn);
            $previewFqdn = str($generatedFqdn)->before('://').'://'.$previewFqdn;
        } else {
            $template = $application->preview_url_template;
            $random = (string) new Cuid2;
            $previewFqdns = [];
            foreach (explode(',', $domainString) as $singleDomain) {
                $singleDomain = trim($singleDomain);
                if ($singleDomain === '') {
                    continue;
                }
                $url = Url::fromString($singleDomain);
                $host = $url->getHost();
                $schema = $url->getScheme();
                $portInt = $url->getPort();
                $port = $portInt !== null ? ':'.$portInt : '';
                $candidate = str_replace('{{random}}', $random, $template);
                $candidate = str_replace('{{domain}}', $host, $candidate);
                $candidate = str_replace('{{pr_id}}', (string) $pull_request_id, $candidate);
                $previewFqdns[] = "$schema://$candidate{$port}";
            }
            $previewFqdn = implode(',', $previewFqdns);
        }

        $domains = json_decode((string) $preview->docker_compose_domains, true) ?: [];
        $domains[$serviceName] = $domains[$serviceName] ?? [];
        $domains[$serviceName]['domain'] = $previewFqdn;
        $preview->docker_compose_domains = json_encode($domains);
        $preview->save();

        return back()->with('success', 'Domain generated.');
    }

    /** Port of Previews::stop(). */
    public function stopPreview(string $project_uuid, string $environment_uuid, string $application_uuid, string $pull_request_id): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('deploy', $application);

        try {
            $server = $application->destination->server;
            if ($server->isSwarm()) {
                instant_remote_process(["docker stack rm {$application->uuid}-{$pull_request_id}"], $server);
            } else {
                $containers = getCurrentApplicationContainerStatus($server, $application->id, (int) $pull_request_id)->toArray();
                $timeout = $application->settings->stopGracePeriodSeconds();
                foreach (collect($containers)->pluck('Names')->toArray() as $containerName) {
                    instant_remote_process(command: [
                        "docker stop --time=$timeout $containerName",
                        "docker rm -f $containerName",
                    ], server: $server, throwError: false);
                }
            }
            GetContainersStatus::run($server);
            $application->refresh();

            return back()->with('success', 'Preview Deployment stopped.');
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in stopPreview().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }
    }

    /** Port of Previews::delete() — soft-deletes immediately, hands cleanup to the existing async job. */
    public function destroyPreview(string $project_uuid, string $environment_uuid, string $application_uuid, string $pull_request_id): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('delete', $application);

        $preview = ApplicationPreview::where('application_id', $application->id)->where('pull_request_id', $pull_request_id)->first();
        if (! $preview) {
            return back()->with('error', 'Preview not found.');
        }

        $preview->delete();
        DeleteResourceJob::dispatch($preview);

        return back()->with('success', 'Preview deletion started. It may take a few moments to complete.');
    }

    public function updateGeneral(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $input = $request->all();

        // Port of updatedBuildPack()'s pre-submit side effects, run only when the client
        // flags that the build-pack <select> itself just changed (not on every save).
        if ($request->boolean('buildPackChanged')) {
            $buildPack = (string) ($input['buildPack'] ?? $application->build_pack);
            if ($buildPack !== 'nixpacks' && $buildPack !== 'railpack') {
                $input['isStatic'] = false;
                $application->settings->is_static = false;
                $application->settings->save();
            } else {
                $this->maybeRegenerateDefaultLabels($application);
            }
            if ($buildPack === 'dockercompose') {
                $input['fqdn'] = null;
                $application->fqdn = null;
                $application->settings->save();
            }
            if ($buildPack === 'static') {
                $input['portsExposes'] = '80';
                $application->ports_exposes = '80';
                $this->maybeRegenerateDefaultLabels($application);
                $input['customNginxConfiguration'] = defaultNginxConfiguration('static');
            }
        }

        $input['portsExposes'] = filled($input['portsExposes'] ?? null)
            ? str($input['portsExposes'])->replace(' ', '')->trim()->toString()
            : null;
        if (filled($input['portsMappings'] ?? null)) {
            $input['portsMappings'] = str($input['portsMappings'])->replace(' ', '')->trim()->toString();
        }

        $validated = Validator::make($input, $this->generalValidationRules(), $this->generalValidationMessages())->validate();

        $oldPortsExposes = $application->ports_exposes;
        $oldIsContainerLabelEscapeEnabled = (bool) $application->settings->is_container_label_escape_enabled;
        $oldDockerComposeLocation = $application->docker_compose_location;
        $oldBaseDirectory = $application->base_directory;

        $warning = $this->applyNormalizedFqdn($application, (string) ($validated['fqdn'] ?? ''));

        $this->syncGeneralFieldsToModel($application, $validated);

        if ($application->isDirty('redirect')) {
            $result = $this->applyRedirectDirection($application, $validated['redirect']);
            if ($result !== null) {
                return $result;
            }
        }
        if ($application->isDirty('dockerfile')) {
            $application->parseHealthcheckFromDockerfile((string) $application->dockerfile);
        }

        $fqdnConflicts = $this->fqdnConflicts($request, $application);
        if ($fqdnConflicts !== null) {
            return back()->with(['domainConflicts' => $fqdnConflicts, 'showDomainConflictModal' => true]);
        }

        if ($application->base_directory && $application->base_directory !== '/') {
            $application->base_directory = rtrim($application->base_directory, '/');
        }
        if ($application->publish_directory && $application->publish_directory !== '/') {
            $application->publish_directory = rtrim($application->publish_directory, '/');
        }

        if ($application->build_pack === 'dockercompose' &&
            ($oldDockerComposeLocation !== $application->docker_compose_location || $oldBaseDirectory !== $application->base_directory)) {
            try {
                $this->reloadComposeFile($application, isInit: false, showToast: false, restoreBaseDirectory: $oldBaseDirectory, restoreDockerComposeLocation: $oldDockerComposeLocation);
            } catch (\Throwable $e) {
                Log::error('Unhandled exception in updateGeneral().', ['error' => $e->getMessage()]);
                $application->docker_compose_location = $oldDockerComposeLocation;
                $application->base_directory = $oldBaseDirectory;

                return back()->with('error', $e->getMessage());
            }
        }

        $application->save();
        if (blank($application->custom_labels) && $application->destination->server->proxyType() !== 'NONE' && ! $application->settings->is_container_label_readonly_enabled) {
            $this->maybeRegenerateDefaultLabels($application, manualReset: true);
        }
        if ($oldPortsExposes !== $application->ports_exposes || $oldIsContainerLabelEscapeEnabled !== (bool) $application->settings->is_container_label_escape_enabled) {
            $this->maybeRegenerateDefaultLabels($application);
        }
        if ($application->build_pack === 'dockerimage') {
            Validator::make(['dockerRegistryImageName' => $application->docker_registry_image_name], [
                'dockerRegistryImageName' => ValidationPatterns::dockerImageNameRules(required: true),
            ])->validate();
        }
        if (filled($application->custom_docker_run_options)) {
            $application->custom_docker_run_options = str($application->custom_docker_run_options)->trim()->toString();
        }
        if (filled($application->dockerfile)) {
            $port = get_port_from_dockerfile($application->dockerfile);
            if ($port && blank($application->ports_exposes)) {
                $application->ports_exposes = (string) $port;
            }
        }

        if ($application->build_pack === 'dockercompose') {
            $application->docker_compose_domains = json_encode($this->parsedServiceDomainsFromRequest($request));
            if ($application->isDirty('docker_compose_domains')) {
                if (! $request->boolean('force_save_domains')) {
                    $result = checkDomainUsage(resource: $application);
                    if ($result['hasConflicts']) {
                        return back()->with(['domainConflicts' => $result['conflicts'], 'showDomainConflictModal' => true]);
                    }
                }
                $application->save();
                $this->maybeRegenerateDefaultLabels($application, manualReset: true);
            }
        }

        $application->custom_labels = filled($validated['customLabels'] ?? null) ? base64_encode($validated['customLabels']) : $application->custom_labels;
        $application->save();

        return back()->with($warning ? [] : ['success' => 'Application settings updated!']);
    }

    public function instantSaveGeneral(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $validated = Validator::make($request->all(), $this->generalValidationRules(), $this->generalValidationMessages())->validate();

        $oldPortsExposes = $application->ports_exposes;
        $oldIsContainerLabelEscapeEnabled = (bool) $application->settings->is_container_label_escape_enabled;
        $oldIsPreserveRepositoryEnabled = (bool) $application->settings->is_preserve_repository_enabled;
        $oldIsSpa = (bool) $application->settings->is_spa;
        $oldIsHttpBasicAuthEnabled = (bool) $application->is_http_basic_auth_enabled;

        $this->syncGeneralFieldsToModel($application, $validated);
        $application->save();

        if ($oldIsSpa !== (bool) $application->settings->is_spa) {
            $application->custom_nginx_configuration = defaultNginxConfiguration($application->settings->is_spa ? 'spa' : 'static');
            $application->save();
        }
        if ($oldIsHttpBasicAuthEnabled !== (bool) $application->is_http_basic_auth_enabled) {
            $application->save();
        }

        if ($oldPortsExposes !== $application->ports_exposes || $oldIsContainerLabelEscapeEnabled !== (bool) $application->settings->is_container_label_escape_enabled) {
            $this->maybeRegenerateDefaultLabels($application);
        }
        if ($oldIsPreserveRepositoryEnabled !== (bool) $application->settings->is_preserve_repository_enabled && ! $application->settings->is_preserve_repository_enabled) {
            $application->fileStorages->each(function ($storage) {
                $storage->is_based_on_git = false;
                $storage->save();
            });
        }
        if ($application->settings->is_container_label_readonly_enabled) {
            $this->maybeRegenerateDefaultLabels($application);
        }

        return back()->with('success', 'Settings saved.');
    }

    public function loadComposeFileEndpoint(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $isInit = $request->boolean('isInit');
        if ($isInit && $application->docker_compose_raw) {
            return back();
        }

        try {
            $this->reloadComposeFile($application, isInit: $isInit, showToast: true);
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in loadComposeFileEndpoint().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Docker compose file loaded.');
    }

    public function generateServiceDomain(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $validated = Validator::make($request->all(), [
            'serviceName' => 'required|string',
        ])->validate();

        $uuid = (string) new Cuid2;
        $domain = generateUrl(server: $application->destination->server, random: $uuid);

        $decoded = json_decode((string) $application->docker_compose_domains, true) ?: [];
        $decoded[$validated['serviceName']] = ['domain' => $domain];
        $application->docker_compose_domains = json_encode($decoded);
        $application->save();

        if ($application->build_pack === 'dockercompose') {
            try {
                $this->reloadComposeFile($application, isInit: false, showToast: false);
            } catch (\Throwable $e) {
                Log::error('Unhandled exception in generateServiceDomain().', ['error' => $e->getMessage()]);
                // Domain was saved; a stale compose parse here isn't fatal.
            }
        }

        return back()->with('success', 'Domain generated.');
    }

    public function getWildcardDomainEndpoint(string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $server = $application->destination->server;
        $application->fqdn = generateUrl(server: $server, random: $application->uuid);
        $application->save();
        $this->maybeRegenerateDefaultLabels($application, manualReset: true);

        return back()->with('success', 'Wildcard domain generated.');
    }

    public function generateNginxConfig(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $type = $request->string('type', 'static')->value();
        $application->custom_nginx_configuration = defaultNginxConfiguration($type);
        $application->save();

        return back()->with('success', 'Nginx configuration generated.');
    }

    public function resetDefaultLabelsEndpoint(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $this->maybeRegenerateDefaultLabels($application, manualReset: $request->boolean('manual', true));

        return back()->with('success', 'Labels reset to defaults.');
    }

    public function setRedirectDirection(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }
        $this->authorize('update', $application);

        $validated = Validator::make($request->all(), [
            'redirect' => 'required|string|in:both,www,non-www',
        ])->validate();

        $result = $this->applyRedirectDirection($application, $validated['redirect']);
        if ($result !== null) {
            return $result;
        }
        $application->save();
        $this->maybeRegenerateDefaultLabels($application, manualReset: true);

        return back()->with('success', 'Redirect updated.');
    }

    /**
     * Sets the redirect direction on the (already-resolved) model, refusing the save if it
     * would redirect to a www domain the application doesn't actually have — matching the
     * original Rollback::setRedirect()'s own guard. Returns a redirect response (the error
     * flash) if the guard tripped, or null to let the caller continue and persist.
     */
    private function applyRedirectDirection(Application $application, string $redirect): ?RedirectResponse
    {
        $application->redirect = $redirect;
        $hasWww = collect($application->fqdns)->filter(fn ($fqdn) => str($fqdn)->contains('www.'))->count();
        if ($hasWww === 0 && $redirect === 'www') {
            return back()->with('error', 'You want to redirect to www, but you do not have a www domain set. Please add www to your domain list and as an A DNS record (if applicable).');
        }

        return null;
    }

    /**
     * Port of General::normalizeFqdnAndWarn() — trims/lowercases/dedupes the comma-separated
     * domain list and applies it to the model. Returns the sslip.io warning message, if any.
     */
    private function applyNormalizedFqdn(Application $application, string $fqdn): ?string
    {
        $fqdn = str($fqdn)->replaceEnd(',', '')->trim()->toString();
        $fqdn = str($fqdn)->replaceStart(',', '')->trim()->toString();
        $domains = str($fqdn)->trim()->explode(',')->filter(fn ($domain) => trim($domain) !== '')->map(function ($domain) {
            $domain = trim($domain);
            Url::fromString($domain, ['http', 'https']);

            return str($domain)->lower();
        });
        $fqdn = $domains->unique()->implode(',');
        $application->fqdn = $fqdn ?: null;
        $warning = $fqdn ? sslipDomainWarning($fqdn) : null;

        return $warning ?: null;
    }

    /**
     * Port of General::checkFqdns() — DNS + domain-conflict validation for the top-level FQDN
     * field, skipped entirely for dockercompose builds (whose domains live per-service in
     * docker_compose_domains instead). Returns false (and flags a conflict in the session) if
     * the caller should stop and show the domain-conflict modal.
     */
    /**
     * @return array<int, mixed>|null null means no conflict (or the check was skipped/forced);
     *                                 a non-null array is the conflict list to flash.
     */
    private function fqdnConflicts(Request $request, Application $application): ?array
    {
        if (blank($application->fqdn) || $application->build_pack === 'dockercompose') {
            return null;
        }
        if ($request->boolean('force_save_domains')) {
            return null;
        }
        $result = checkDomainUsage(resource: $application);

        return $result['hasConflicts'] ? $result['conflicts'] : null;
    }

    /**
     * Port of General::regenerateCustomLabelsIfNeeded()/resetDefaultLabels()'s shared core:
     * regenerates the container labels from the application's current settings and persists
     * them. Auto-triggered calls (manualReset: false) are a no-op unless labels are in
     * Coolify-managed (readonly) mode — matching the original's own gate exactly, so a user
     * hand-editing labels never has their edits silently overwritten by a field change
     * elsewhere in the form.
     */
    private function maybeRegenerateDefaultLabels(Application $application, bool $manualReset = false): void
    {
        if (! (bool) $application->settings->is_container_label_readonly_enabled && ! $manualReset) {
            return;
        }

        $customLabels = (string) str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
        $application->custom_labels = base64_encode($customLabels);
        $application->save();

        if ($application->build_pack === 'dockercompose' && $application->docker_compose_raw) {
            try {
                $this->reloadComposeFile($application, isInit: false, showToast: false);
            } catch (\Throwable $e) {
                Log::error('Unhandled exception in maybeRegenerateDefaultLabels().', ['error' => $e->getMessage()]);
                // Labels are already persisted; a stale compose parse here isn't fatal.
            }
        }
    }

    /**
     * Port of General::loadComposeFile()/Application::loadComposeFile() — clones the repo on
     * the destination server over SSH to read the compose file, then re-parses it. Genuinely
     * SSH-touching; carries the standard untested-happy-path gap (docs/smoketest.md).
     *
     * @return array{parsedServices: mixed, initialDockerComposeLocation: ?string}
     */
    private function reloadComposeFile(Application $application, bool $isInit, bool $showToast, ?string $restoreBaseDirectory = null, ?string $restoreDockerComposeLocation = null): array
    {
        $result = $application->loadComposeFile($isInit, $restoreBaseDirectory, $restoreDockerComposeLocation);
        $application->refresh();

        return $result ?? ['parsedServices' => null, 'initialDockerComposeLocation' => $application->docker_compose_location];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function syncGeneralFieldsToModel(Application $application, array $validated): void
    {
        $application->name = $validated['name'];
        $application->description = $validated['description'] ?? null;
        $application->git_repository = $validated['gitRepository'];
        $application->git_branch = $validated['gitBranch'];
        $application->git_commit_sha = $validated['gitCommitSha'] ?? null;
        $application->install_command = $validated['installCommand'] ?? null;
        $application->build_command = $validated['buildCommand'] ?? null;
        $application->start_command = $validated['startCommand'] ?? null;
        $application->build_pack = $validated['buildPack'];
        $application->static_image = $validated['staticImage'];
        $application->base_directory = $validated['baseDirectory'];
        $application->publish_directory = $validated['publishDirectory'] ?? null;
        $application->ports_exposes = $validated['portsExposes'] ?? null;
        $application->ports_mappings = $validated['portsMappings'] ?? null;
        $application->custom_network_aliases = $validated['customNetworkAliases'] ?? null;
        $application->dockerfile = $validated['dockerfile'] ?? null;
        $application->dockerfile_location = $validated['dockerfileLocation'] ?? null;
        $application->dockerfile_target_build = $validated['dockerfileTargetBuild'] ?? null;
        $application->docker_registry_image_name = $validated['dockerRegistryImageName'] ?? null;
        $application->docker_registry_image_tag = $validated['dockerRegistryImageTag'] ?? null;
        $application->docker_compose_location = $validated['dockerComposeLocation'] ?? null;
        $application->docker_compose_custom_start_command = $validated['dockerComposeCustomStartCommand'] ?? null;
        $application->docker_compose_custom_build_command = $validated['dockerComposeCustomBuildCommand'] ?? null;
        $application->custom_labels = array_key_exists('customLabels', $validated) && $validated['customLabels'] !== null
            ? base64_encode($validated['customLabels'])
            : $application->custom_labels;
        $application->custom_docker_run_options = $validated['customDockerRunOptions'] ?? null;
        $application->pre_deployment_command = $validated['preDeploymentCommand'] ?? null;
        $application->pre_deployment_command_container = $validated['preDeploymentCommandContainer'] ?? null;
        $application->post_deployment_command = $validated['postDeploymentCommand'] ?? null;
        $application->post_deployment_command_container = $validated['postDeploymentCommandContainer'] ?? null;
        $application->custom_nginx_configuration = $validated['customNginxConfiguration'] ?? null;
        $application->is_http_basic_auth_enabled = (bool) ($validated['isHttpBasicAuthEnabled'] ?? false);
        $application->http_basic_auth_username = $validated['httpBasicAuthUsername'] ?? null;
        $application->http_basic_auth_password = $validated['httpBasicAuthPassword'] ?? null;
        $application->watch_paths = $validated['watchPaths'] ?? null;
        $application->redirect = $validated['redirect'];

        $application->settings->is_static = (bool) ($validated['isStatic'] ?? false);
        $application->settings->is_spa = (bool) ($validated['isSpa'] ?? false);
        $application->settings->is_build_server_enabled = (bool) ($validated['isBuildServerEnabled'] ?? false);
        $application->settings->is_preserve_repository_enabled = (bool) ($validated['isPreserveRepositoryEnabled'] ?? false);
        $application->settings->is_container_label_escape_enabled = (bool) ($validated['isContainerLabelEscapeEnabled'] ?? false);
        $application->settings->is_container_label_readonly_enabled = (bool) ($validated['isContainerLabelReadonlyEnabled'] ?? false);
        $application->settings->save();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parsedServiceDomainsFromRequest(Request $request): array
    {
        $sanitized = (array) $request->input('parsedServiceDomains', []);
        $originalDomains = [];
        foreach ($sanitized as $key => $value) {
            $originalDomains[$key] = $value;
        }

        return $originalDomains;
    }

    /**
     * @return array<string, mixed>
     */
    private function generalValidationRules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'fqdn' => 'nullable',
            'gitRepository' => 'required',
            'gitBranch' => ['required', 'string', new ValidGitBranch],
            'gitCommitSha' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._\-\/]*$/'],
            'installCommand' => ValidationPatterns::shellSafeCommandRules(),
            'buildCommand' => ValidationPatterns::shellSafeCommandRules(),
            'startCommand' => ValidationPatterns::shellSafeCommandRules(),
            'buildPack' => 'required',
            'staticImage' => 'required',
            'baseDirectory' => array_merge(['required'], array_slice(ValidationPatterns::directoryPathRules(), 1)),
            'publishDirectory' => ValidationPatterns::directoryPathRules(),
            'portsExposes' => ['nullable', 'string', 'regex:/^(\d+)(,\d+)*$/'],
            'portsMappings' => ValidationPatterns::portMappingRules(),
            'customNetworkAliases' => 'nullable',
            'dockerfile' => 'nullable',
            'dockerRegistryImageName' => ValidationPatterns::dockerImageNameRules(),
            'dockerRegistryImageTag' => ValidationPatterns::dockerImageTagRules(),
            'dockerfileLocation' => ValidationPatterns::filePathRules(),
            'dockerComposeLocation' => ValidationPatterns::filePathRules(),
            'dockerfileTargetBuild' => ValidationPatterns::dockerTargetRules(),
            'dockerComposeCustomStartCommand' => ValidationPatterns::shellSafeCommandRules(),
            'dockerComposeCustomBuildCommand' => ValidationPatterns::shellSafeCommandRules(),
            'customLabels' => 'nullable',
            'customDockerRunOptions' => ValidationPatterns::shellSafeCommandRules(2000),
            'preDeploymentCommand' => 'nullable',
            'preDeploymentCommandContainer' => ['nullable', ...ValidationPatterns::containerNameRules()],
            'postDeploymentCommand' => 'nullable',
            'postDeploymentCommandContainer' => ['nullable', ...ValidationPatterns::containerNameRules()],
            'customNginxConfiguration' => 'nullable',
            'isStatic' => 'boolean',
            'isSpa' => 'boolean',
            'isBuildServerEnabled' => 'boolean',
            'isContainerLabelEscapeEnabled' => 'boolean',
            'isContainerLabelReadonlyEnabled' => 'boolean',
            'isPreserveRepositoryEnabled' => 'boolean',
            'isHttpBasicAuthEnabled' => 'boolean',
            'httpBasicAuthUsername' => 'nullable|string',
            'httpBasicAuthPassword' => 'nullable|string',
            'watchPaths' => 'nullable',
            'redirect' => 'string|required',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function generalValidationMessages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                ...ValidationPatterns::filePathMessages('dockerfileLocation', 'Dockerfile'),
                ...ValidationPatterns::filePathMessages('dockerComposeLocation', 'Docker Compose'),
                'baseDirectory.regex' => 'The base directory must be a valid path starting with / and containing only safe characters.',
                'publishDirectory.regex' => 'The publish directory must be a valid path starting with / and containing only safe characters.',
                'dockerfileTargetBuild.regex' => 'The Dockerfile target build must contain only alphanumeric characters, dots, hyphens, and underscores.',
                'dockerComposeCustomStartCommand.regex' => 'The Docker Compose start command contains invalid characters.',
                'dockerComposeCustomBuildCommand.regex' => 'The Docker Compose build command contains invalid characters.',
                'customDockerRunOptions.regex' => 'The custom Docker run options contain invalid characters.',
                'installCommand.regex' => 'The install command contains invalid characters.',
                'buildCommand.regex' => 'The build command contains invalid characters.',
                'startCommand.regex' => 'The start command contains invalid characters.',
                'preDeploymentCommandContainer.regex' => 'The pre-deployment command container name must contain only alphanumeric characters, dots, hyphens, and underscores.',
                'postDeploymentCommandContainer.regex' => 'The post-deployment command container name must contain only alphanumeric characters, dots, hyphens, and underscores.',
                'name.required' => 'The Name field is required.',
                'gitRepository.required' => 'The Git Repository field is required.',
                'gitBranch.required' => 'The Git Branch field is required.',
                'buildPack.required' => 'The Build Pack field is required.',
                'staticImage.required' => 'The Static Image field is required.',
                'baseDirectory.required' => 'The Base Directory field is required.',
                'portsExposes.regex' => 'Ports exposes must be a comma-separated list of port numbers (e.g. 3000,3001).',
                ...ValidationPatterns::portMappingMessages(),
                'redirect.required' => 'The Redirect setting is required.',
            ]
        );
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function generalTabProps(Application $application, array $parameters): array
    {
        $environment = $application->environment;
        $server = $application->destination->server;

        $parsedServices = null;
        if ($application->build_pack === 'dockercompose' && $application->docker_compose_raw) {
            try {
                $parsedServices = $application->parse();
            } catch (\Throwable $e) {
                Log::error('Unhandled exception in generalTabProps().', ['error' => $e->getMessage()]);
                $parsedServices = collect([]);
            }
        }

        $composeServices = [];
        if ($parsedServices) {
            foreach ((array) data_get($parsedServices, 'services', []) as $serviceName => $service) {
                $composeServices[] = [
                    'name' => $serviceName,
                    'sanitizedKey' => str($serviceName)->replace('-', '_')->replace('.', '_')->value(),
                    'isDatabaseImage' => isDatabaseImage(data_get($service, 'image')),
                ];
            }
        }

        $detectedPort = $application->detectPortFromEnvironment();
        $detectedPortInfo = null;
        if ($detectedPort) {
            $portsExposesArray = $application->ports_exposes_array;
            $detectedPortInfo = [
                'port' => $detectedPort,
                'matches' => in_array($detectedPort, $portsExposesArray),
                'isEmpty' => empty($portsExposesArray),
            ];
        }

        return [
            'general' => [
                'name' => $application->name,
                'description' => $application->description,
                'fqdn' => $application->fqdn,
                'gitRepository' => $application->git_repository,
                'gitBranch' => $application->git_branch,
                'gitCommitSha' => $application->git_commit_sha,
                'installCommand' => $application->install_command,
                'buildCommand' => $application->build_command,
                'startCommand' => $application->start_command,
                'buildPack' => $application->build_pack,
                'staticImage' => $application->static_image,
                'baseDirectory' => $application->base_directory,
                'publishDirectory' => $application->publish_directory,
                'portsExposes' => $application->ports_exposes,
                'portsMappings' => $application->ports_mappings,
                'customNetworkAliases' => $application->custom_network_aliases,
                'dockerfile' => $application->dockerfile,
                'dockerfileLocation' => $application->dockerfile_location,
                'dockerfileTargetBuild' => $application->dockerfile_target_build,
                'dockerRegistryImageName' => $application->docker_registry_image_name,
                'dockerRegistryImageTag' => $application->docker_registry_image_tag,
                'dockerComposeLocation' => $application->docker_compose_location,
                'dockerCompose' => $application->docker_compose,
                'dockerComposeRaw' => $application->docker_compose_raw,
                'dockerComposeCustomStartCommand' => $application->docker_compose_custom_start_command,
                'dockerComposeCustomBuildCommand' => $application->docker_compose_custom_build_command,
                'customLabels' => $application->parseContainerLabels(),
                'customDockerRunOptions' => $application->custom_docker_run_options,
                'preDeploymentCommand' => $application->pre_deployment_command,
                'preDeploymentCommandContainer' => $application->pre_deployment_command_container,
                'postDeploymentCommand' => $application->post_deployment_command,
                'postDeploymentCommandContainer' => $application->post_deployment_command_container,
                'customNginxConfiguration' => $application->custom_nginx_configuration,
                'isHttpBasicAuthEnabled' => (bool) $application->is_http_basic_auth_enabled,
                'httpBasicAuthUsername' => $application->http_basic_auth_username,
                'httpBasicAuthPassword' => $application->http_basic_auth_password,
                'watchPaths' => $application->watch_paths,
                'redirect' => $application->redirect,
                'isStatic' => (bool) $application->settings->is_static,
                'isSpa' => (bool) $application->settings->is_spa,
                'isBuildServerEnabled' => (bool) $application->settings->is_build_server_enabled,
                'isPreserveRepositoryEnabled' => (bool) $application->settings->is_preserve_repository_enabled,
                'isContainerLabelEscapeEnabled' => (bool) $application->settings->is_container_label_escape_enabled,
                'isContainerLabelReadonlyEnabled' => (bool) $application->settings->is_container_label_readonly_enabled,
                'isRawComposeDeploymentEnabled' => (bool) $application->settings->is_raw_compose_deployment_enabled,
                'couldSetBuildCommands' => $application->could_set_build_commands(),
                'isSwarm' => (bool) $server?->isSwarm(),
                'additionalServersCount' => $application->additional_servers->count(),
                'isGithubBasedPrivateRepo' => $application->is_github_based() && ! $application->is_public_repository(),
                'composeParsingVersion' => isDev() ? $application->compose_parsing_version : null,
                'detectedPortInfo' => $detectedPortInfo,
                'dockerComposeBuildCommandPreview' => $this->dockerComposeBuildCommandPreview($application),
                'dockerComposeStartCommandPreview' => $this->dockerComposeStartCommandPreview($application),
                'composeServices' => $composeServices,
                'parsedServiceDomains' => $this->sanitizeServiceDomainKeys($application->docker_compose_domains),
            ],
            'resourceDetails' => [
                'resource' => ['name' => $application->name, 'uuid' => $application->uuid],
                'environment' => ['name' => $environment?->name, 'uuid' => $environment?->uuid],
                'project' => ['name' => $environment?->project?->name, 'uuid' => $environment?->project?->uuid],
                'server' => $server ? ['name' => $server->name, 'uuid' => $server->uuid] : null,
            ],
            'generalUrls' => [
                'update' => route('project.application.general.update', $parameters),
                'instantSave' => route('project.application.general.instant-save', $parameters),
                'loadCompose' => route('project.application.general.load-compose', $parameters),
                'generateServiceDomain' => route('project.application.general.generate-domain', $parameters),
                'wildcardDomain' => route('project.application.general.wildcard-domain', $parameters),
                'generateNginxConfig' => route('project.application.general.generate-nginx', $parameters),
                'resetLabels' => route('project.application.general.reset-labels', $parameters),
                'setRedirect' => route('project.application.general.redirect', $parameters),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeServiceDomainKeys(?string $jsonDomains): array
    {
        $parsed = $jsonDomains ? json_decode($jsonDomains, true) : [];
        $sanitized = [];
        foreach ((array) $parsed as $serviceName => $domain) {
            $key = str($serviceName)->replace('-', '_')->replace('.', '_')->value();
            $sanitized[$key] = $domain;
        }

        return $sanitized;
    }

    private function dockerComposeBuildCommandPreview(Application $application): string
    {
        if (blank($application->docker_compose_custom_build_command)) {
            return '';
        }
        $normalizedBase = $application->base_directory === '/' ? '' : rtrim((string) $application->base_directory, '/');
        $command = injectDockerComposeFlags(
            $application->docker_compose_custom_build_command,
            ".{$normalizedBase}{$application->docker_compose_location}",
            ApplicationDeploymentJob::BUILD_TIME_ENV_PATH
        );
        if (! $application->settings->use_build_secrets) {
            $buildTimeEnvs = $application->environment_variables()->where('is_buildtime', true)->get();
            if ($buildTimeEnvs->isNotEmpty()) {
                $buildArgs = generateDockerBuildArgs($buildTimeEnvs);
                $command = injectDockerComposeBuildArgs($command, $buildArgs->implode(' '));
            }
        }

        return $command;
    }

    private function dockerComposeStartCommandPreview(Application $application): string
    {
        if (blank($application->docker_compose_custom_start_command)) {
            return '';
        }
        $normalizedBase = $application->base_directory === '/' ? '' : rtrim((string) $application->base_directory, '/');

        return injectDockerComposeFlags(
            $application->docker_compose_custom_start_command,
            ".{$normalizedBase}{$application->docker_compose_location}",
            '{workdir}/.env'
        );
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function tabProps(string $tab, Application $application, array $parameters): array
    {
        return match ($tab) {
            'tags' => $this->tagsTabProps($application, $parameters, 'project.application'),
            'danger' => $this->dangerTabProps($application, $parameters, 'project.application'),
            'resource-limits' => $this->resourceLimitsTabProps($application, $parameters, 'project.application'),
            'resource-operations' => $this->resourceOperationsTabProps($application, $parameters, 'project.application'),
            'scheduled-tasks' => $this->scheduledTasksTabProps($application, $parameters, 'project.application', request()->route('task_uuid')),
            'environment-variables' => $this->environmentVariablesTabProps($application, $parameters, 'project.application'),
            'persistent-storage' => $this->storagesTabProps($application, $parameters, 'project.application'),
            'webhooks' => $this->webhooksTabProps($application, $parameters, 'project.application'),
            'swarm' => $this->swarmTabProps($application, $parameters),
            'rollback' => $this->rollbackTabProps($application, $parameters),
            'configuration' => $this->generalTabProps($application, $parameters),
            'preview-deployments' => $this->previewDeploymentsTabProps($application, $parameters),
            'advanced' => $this->advancedTabProps($application, $parameters),
            'healthcheck' => $this->healthcheckTabProps($application, $parameters),
            'servers' => $this->serversTabProps($application, $parameters),
            'source' => $this->sourceTabProps($application, $parameters),
            default => [],
        };
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function sourceTabProps(Application $application, array $parameters): array
    {
        $privateKeys = PrivateKey::whereTeamId(currentTeam()->id)->get()
            ->reject(fn ($key) => $key->id === $application->private_key_id)
            ->map(fn ($key) => ['id' => $key->id, 'name' => $key->name])
            ->values();

        $sources = currentTeam()->sources()
            ->filter(fn ($source) => filled($source->app_id))
            ->reject(fn ($source) => $source->id === $application->source_id)
            ->sortBy('name')
            ->map(fn ($source) => [
                'id' => $source->id,
                'name' => $source->name,
                'type' => $source->getMorphClass(),
                'organization' => $source->organization,
                'isCurrent' => $application->source_id === $source->id,
            ])
            ->values();

        return [
            'source' => [
                'gitRepository' => $application->git_repository,
                'gitBranch' => $application->git_branch,
                'gitCommitSha' => $application->git_commit_sha,
                'privateKeyId' => $application->private_key_id,
                'privateKeyName' => data_get($application, 'private_key.name'),
                'currentSourceName' => data_get($application, 'source.name', 'No source connected'),
                'isSourcePublic' => (bool) data_get($application, 'source.is_public', true),
                'installationPath' => $application->source instanceof GithubApp && ! $application->source->is_public
                    ? getInstallationPath($application->source)
                    : null,
                'gitBranchLocation' => $application->gitBranchLocation,
                'gitCommits' => $application->gitCommits,
                'privateKeys' => $privateKeys,
                'sources' => $sources,
            ],
            'sourceUrls' => [
                'update' => route('project.application.source.update', $parameters),
                'setPrivateKey' => route('project.application.source.set-private-key', $parameters),
                'changeSource' => route('project.application.source.change', $parameters),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function serversTabProps(Application $application, array $parameters): array
    {
        $primaryDestination = $application->destination;
        $primaryServer = $primaryDestination->server;

        $additionalNetworks = $application->additional_networks->map(fn ($network) => [
            'id' => $network->id,
            'serverId' => $network->server->id,
            'serverName' => $network->server->name,
            'network' => $network->network,
            'status' => $network->pivot->status,
            'isRunning' => str($network->pivot->status)->startsWith('running'),
        ])->values()->toArray();

        $hasPersistentStorage = $application->persistentStorages()->count() > 0;
        $canManageAdditionalServers = $application->build_pack !== 'dockercompose';

        $availableNetworks = [];
        if ($canManageAdditionalServers && ! $hasPersistentStorage) {
            $existingNetworkIds = collect([$primaryDestination])->merge($application->additional_networks)->pluck('id');
            $additionalServerIds = $application->additional_servers->pluck('id');

            $availableNetworks = Server::isUsable()->get()
                ->flatMap(fn ($server) => $server->standaloneDockers)
                ->reject(fn ($network) => $existingNetworkIds->contains($network->id))
                ->reject(fn ($network) => $network->server->id === $primaryServer->id)
                ->reject(fn ($network) => $additionalServerIds->contains($network->server->id))
                ->map(fn ($network) => [
                    'id' => $network->id,
                    'serverId' => $network->server->id,
                    'serverName' => $network->server->name,
                    'name' => $network->name,
                ])->values()->toArray();
        }

        return [
            'servers' => [
                'primary' => [
                    'networkId' => $primaryDestination->id,
                    'serverId' => $primaryServer->id,
                    'serverName' => $primaryServer->name,
                    'network' => $primaryDestination->network,
                    'status' => $application->realStatus(),
                ],
                'additionalNetworks' => $additionalNetworks,
                'availableNetworks' => $availableNetworks,
                'hasPersistentStorage' => $hasPersistentStorage,
                'canManageAdditionalServers' => $canManageAdditionalServers,
            ],
            'serversUrls' => [
                'redeploy' => route('project.application.servers.redeploy', $parameters),
                'stop' => route('project.application.servers.stop', $parameters),
                'promote' => route('project.application.servers.promote', $parameters),
                'add' => route('project.application.servers.add', $parameters),
                'remove' => route('project.application.servers.remove', $parameters),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function healthcheckTabProps(Application $application, array $parameters): array
    {
        return [
            'healthcheck' => [
                'healthCheckEnabled' => (bool) $application->health_check_enabled,
                'healthCheckType' => $application->health_check_type ?? 'http',
                'healthCheckCommand' => $application->health_check_command,
                'healthCheckMethod' => $application->health_check_method,
                'healthCheckScheme' => $application->health_check_scheme,
                'healthCheckHost' => $application->health_check_host,
                'healthCheckPort' => $application->health_check_port,
                'healthCheckPath' => $application->health_check_path,
                'healthCheckReturnCode' => $application->health_check_return_code,
                'healthCheckResponseText' => $application->health_check_response_text,
                'healthCheckInterval' => $application->health_check_interval,
                'healthCheckTimeout' => $application->health_check_timeout,
                'healthCheckRetries' => $application->health_check_retries,
                'healthCheckStartPeriod' => $application->health_check_start_period,
                'customHealthcheckFound' => (bool) $application->custom_healthcheck_found,
            ],
            'healthcheckUrls' => [
                'update' => route('project.application.healthcheck.update', $parameters),
                'toggle' => route('project.application.healthcheck.toggle', $parameters),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function advancedTabProps(Application $application, array $parameters): array
    {
        $settings = $application->settings;

        return [
            'advanced' => [
                'isForceHttpsEnabled' => (bool) $application->isForceHttpsEnabled(),
                'isGzipEnabled' => (bool) $application->isGzipEnabled(),
                'isStripprefixEnabled' => (bool) $application->isStripprefixEnabled(),
                'isLogDrainEnabled' => (bool) $application->isLogDrainEnabled(),
                'isGitSubmodulesEnabled' => (bool) $settings->is_git_submodules_enabled,
                'isGitLfsEnabled' => (bool) $settings->is_git_lfs_enabled,
                'isGitShallowCloneEnabled' => (bool) ($settings->is_git_shallow_clone_enabled ?? false),
                'isPreviewDeploymentsEnabled' => (bool) $settings->is_preview_deployments_enabled,
                'isPrDeploymentsPublicEnabled' => (bool) ($settings->is_pr_deployments_public_enabled ?? false),
                'isAutoDeployEnabled' => (bool) $settings->is_auto_deploy_enabled,
                'isGpuEnabled' => (bool) $settings->is_gpu_enabled,
                'gpuDriver' => $settings->gpu_driver,
                'gpuCount' => $settings->gpu_count,
                'gpuDeviceIds' => $settings->gpu_device_ids,
                'gpuOptions' => $settings->gpu_options,
                'isBuildServerEnabled' => (bool) $settings->is_build_server_enabled,
                'isConsistentContainerNameEnabled' => (bool) $settings->is_consistent_container_name_enabled,
                'customInternalName' => $settings->custom_internal_name,
                'isRawComposeDeploymentEnabled' => (bool) $settings->is_raw_compose_deployment_enabled,
                'isConnectToDockerNetworkEnabled' => (bool) $settings->connect_to_docker_network,
                'disableBuildCache' => (bool) $settings->disable_build_cache,
                'injectBuildArgsToDockerfile' => (bool) ($settings->inject_build_args_to_dockerfile ?? true),
                'includeSourceCommitInBuild' => (bool) ($settings->include_source_commit_in_build ?? false),
                'stopGracePeriod' => $settings->stop_grace_period,
                'maxRestartCount' => $application->max_restart_count ?? 10,
                'isContainerLabelReadonlyEnabled' => (bool) $settings->is_container_label_readonly_enabled,
                'gitBased' => $application->git_based(),
                'buildPack' => $application->build_pack,
            ],
            'advancedUrls' => [
                'instantSave' => route('project.application.advanced.instant-save', $parameters),
                'update' => route('project.application.advanced.update', $parameters),
                'customName' => route('project.application.advanced.custom-name', $parameters),
                'stopGracePeriod' => route('project.application.advanced.stop-grace-period', $parameters),
                'maxRestartCount' => route('project.application.advanced.max-restart-count', $parameters),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function previewDeploymentsTabProps(Application $application, array $parameters): array
    {
        $realPreviewUrlTemplate = $application->preview_url_template;
        if (filled($application->fqdn)) {
            $firstFqdn = str($application->fqdn)->before(',')->value();
            $host = Url::fromString($firstFqdn)->getHost();
            $realPreviewUrlTemplate = str($realPreviewUrlTemplate)->replace('{{domain}}', $host)->toString();
        }

        $previews = $application->previews->map(function (ApplicationPreview $preview) use ($parameters) {
            $composeDomains = [];
            foreach ((array) (json_decode((string) $preview->docker_compose_domains, true) ?: []) as $serviceName => $service) {
                $composeDomains[] = ['serviceName' => $serviceName, 'domain' => data_get($service, 'domain')];
            }
            $previewParameters = [...$parameters, 'pull_request_id' => $preview->pull_request_id];

            return [
                'id' => $preview->id,
                'pullRequestId' => $preview->pull_request_id,
                'pullRequestHtmlUrl' => $preview->pull_request_html_url,
                'status' => $preview->status,
                'fqdn' => $preview->fqdn,
                'dockerRegistryImageTag' => $preview->docker_registry_image_tag,
                'composeDomains' => $composeDomains,
                'deploymentLogsUrl' => route('project.application.deployment.index', $previewParameters),
                'applicationLogsUrl' => route('project.application.logs', $previewParameters),
                'urls' => [
                    'domainUpdate' => route('project.application.previews.domain.update', $previewParameters),
                    'domainGenerate' => route('project.application.previews.domain.generate', $previewParameters),
                    'composeDomainUpdate' => route('project.application.previews.compose-domain.update', $previewParameters),
                    'composeDomainGenerate' => route('project.application.previews.compose-domain.generate', $previewParameters),
                    'deploy' => route('project.application.previews.deploy', $previewParameters),
                    'forceDeploy' => route('project.application.previews.force-deploy', $previewParameters),
                    'stop' => route('project.application.previews.stop', $previewParameters),
                    'destroy' => route('project.application.previews.destroy', $previewParameters),
                ],
            ];
        })->values()->toArray();

        return [
            'previews' => [
                'previewUrlTemplate' => $application->preview_url_template,
                'realPreviewUrlTemplate' => $realPreviewUrlTemplate,
                'isGithubBased' => $application->is_github_based(),
                'buildPack' => $application->build_pack,
                'additionalServersCount' => $application->additional_servers->count(),
                'primaryServerName' => $application->destination->server->name,
                'canDeploy' => auth()->user()->can('deploy', $application),
                'canDelete' => auth()->user()->can('delete', $application),
                'deployments' => $previews,
            ],
            'previewUrls' => [
                'updateTemplate' => route('project.application.previews.template.update', $parameters),
                'loadPullRequests' => route('project.application.previews.load-prs', $parameters),
                'store' => route('project.application.previews.store', $parameters),
                'addAndDeploy' => route('project.application.previews.add-deploy', $parameters),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function rollbackTabProps(Application $application, array $parameters): array
    {
        return [
            'rollback' => [
                'dockerImagesToKeep' => $application->settings->docker_images_to_keep ?? 2,
                'serverRetentionDisabled' => (bool) ($application->destination->server->settings->disable_application_image_retention ?? false),
                'canDeploy' => auth()->user()->can('deploy', $application),
            ],
            'rollbackUrls' => [
                'saveSettings' => route('project.application.rollback.save-settings', $parameters),
                'loadImages' => route('project.application.rollback.load-images', $parameters),
                'deploy' => route('project.application.rollback.deploy', $parameters),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function swarmTabProps(Application $application, array $parameters): array
    {
        return [
            'swarm' => [
                'swarmReplicas' => $application->swarm_replicas,
                'swarmPlacementConstraints' => $application->swarm_placement_constraints ? base64_decode($application->swarm_placement_constraints) : null,
                'isSwarmOnlyWorkerNodes' => (bool) $application->settings->is_swarm_only_worker_nodes,
            ],
            'swarmUpdateUrl' => route('project.application.swarm.update', $parameters),
        ];
    }

    /**
     * The `key` lets the page mark a link active by the current tab prop rather than by exact
     * URL, so the task detail page (/tasks/{task_uuid}) still highlights Scheduled Tasks
     * (the Livewire sidebar's startsWith() behavior) — same pattern as the Service router.
     *
     * @param  array<string, string>  $parameters
     * @return array<int, array{key: string, label: string, href: string}>
     */
    private function tabLinks(Application $application, array $parameters): array
    {
        return [
            ['key' => 'configuration', 'label' => 'General', 'href' => route('project.application.configuration', $parameters)],
            ['key' => 'advanced', 'label' => 'Advanced', 'href' => route('project.application.advanced', $parameters)],
            ...($application->destination->server->isSwarm() ? [['key' => 'swarm', 'label' => 'Swarm', 'href' => route('project.application.swarm', $parameters)]] : []),
            ['key' => 'environment-variables', 'label' => 'Environment Variables', 'href' => route('project.application.environment-variables', $parameters)],
            ['key' => 'persistent-storage', 'label' => 'Persistent Storage', 'href' => route('project.application.persistent-storage', $parameters)],
            ...($application->git_based() ? [['key' => 'source', 'label' => 'Git Source', 'href' => route('project.application.source', $parameters)]] : []),
            ['key' => 'servers', 'label' => 'Servers', 'href' => route('project.application.servers', $parameters)],
            ['key' => 'scheduled-tasks', 'label' => 'Scheduled Tasks', 'href' => route('project.application.scheduled-tasks.show', $parameters)],
            ['key' => 'webhooks', 'label' => 'Webhooks', 'href' => route('project.application.webhooks', $parameters)],
            ...($application->git_based() || $application->build_pack === 'dockerimage' ? [['key' => 'preview-deployments', 'label' => 'Preview Deployments', 'href' => route('project.application.preview-deployments', $parameters)]] : []),
            ...($application->build_pack !== 'dockercompose' ? [['key' => 'healthcheck', 'label' => 'Healthcheck', 'href' => route('project.application.healthcheck', $parameters)]] : []),
            ['key' => 'rollback', 'label' => 'Rollback', 'href' => route('project.application.rollback', $parameters)],
            ['key' => 'resource-limits', 'label' => 'Resource Limits', 'href' => route('project.application.resource-limits', $parameters)],
            ['key' => 'resource-operations', 'label' => 'Resource Operations', 'href' => route('project.application.resource-operations', $parameters)],
            ['key' => 'metrics', 'label' => 'Metrics', 'href' => route('project.application.metrics', $parameters)],
            ['key' => 'tags', 'label' => 'Tags', 'href' => route('project.application.tags', $parameters)],
            ['key' => 'danger', 'label' => 'Danger Zone', 'href' => route('project.application.danger', $parameters)],
        ];
    }
}
