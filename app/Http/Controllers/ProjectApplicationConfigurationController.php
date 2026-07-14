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
use App\Models\Application;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
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
 * that's genuinely Application-only — see ManagesResourceWebhooks' docblock); and Swarm (Phase
 * 67, no concern needed — it's Application's own deprecated feature with no Database/Service
 * equivalent at all). "Clone" is Application's own — it delegates to clone_application(), the
 * comprehensive helper already proven by Project\CloneMe, rather than duplicating
 * per-child-type cloning logic inline the way Database's and Service's clone() methods each had
 * to.
 *
 * The shell's heading (deploy/restart/stop/force-deploy/status-polling — Phase 64) is
 * ApplicationHeading.jsx, built from ManagesApplicationHeading's props on its second
 * consumer (the first being ProjectLogsController's application logs page, ported earlier
 * alongside deploy/restart/stop/check-status themselves). No new deployment routes were
 * needed here — only the props pointing at the existing ones.
 *
 * Still routed to Livewire: General, Advanced, Git Source, Servers, Preview Deployments,
 * Healthcheck, Rollback — each either application-only business logic (servers' full
 * multi-server Destination behavior) or a large enough unit to deserve its own phase.
 * Environment Variables' preview-deployment set, build secrets, and sort-alphabetically toggle
 * stay with Preview Deployments' own future conversion — see
 * ManagesResourceEnvironmentVariables' docblock.
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
            default => [],
        };
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
            ['key' => 'source', 'label' => 'Git Source', 'href' => route('project.application.source', $parameters)],
            ['key' => 'servers', 'label' => 'Servers', 'href' => route('project.application.servers', $parameters)],
            ['key' => 'scheduled-tasks', 'label' => 'Scheduled Tasks', 'href' => route('project.application.scheduled-tasks.show', $parameters)],
            ['key' => 'webhooks', 'label' => 'Webhooks', 'href' => route('project.application.webhooks', $parameters)],
            ['key' => 'preview-deployments', 'label' => 'Preview Deployments', 'href' => route('project.application.preview-deployments', $parameters)],
            ['key' => 'healthcheck', 'label' => 'Healthcheck', 'href' => route('project.application.healthcheck', $parameters)],
            ['key' => 'rollback', 'label' => 'Rollback', 'href' => route('project.application.rollback', $parameters)],
            ['key' => 'resource-limits', 'label' => 'Resource Limits', 'href' => route('project.application.resource-limits', $parameters)],
            ['key' => 'resource-operations', 'label' => 'Resource Operations', 'href' => route('project.application.resource-operations', $parameters)],
            ['key' => 'metrics', 'label' => 'Metrics', 'href' => route('project.application.metrics', $parameters)],
            ['key' => 'tags', 'label' => 'Tags', 'href' => route('project.application.tags', $parameters)],
            ['key' => 'danger', 'label' => 'Danger Zone', 'href' => route('project.application.danger', $parameters)],
        ];
    }
}
