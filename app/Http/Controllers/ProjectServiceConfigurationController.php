<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesResourceDanger;
use App\Http\Controllers\Concerns\ManagesResourceEnvironmentVariables;
use App\Http\Controllers\Concerns\ManagesResourceOperations;
use App\Http\Controllers\Concerns\ManagesResourceScheduledTasks;
use App\Http\Controllers\Concerns\ManagesResourceStorages;
use App\Http\Controllers\Concerns\ManagesResourceTags;
use App\Http\Controllers\Concerns\ManagesResourceWebhooks;
use App\Http\Controllers\Concerns\NormalizesServiceFqdns;
use App\Http\Controllers\Concerns\ResolvesProjectResources;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Support\ValidationPatterns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Visus\Cuid2\Cuid2;

/**
 * React port of App\Livewire\Project\Service\Configuration's shell plus 4 of its 8 tabs —
 * Tags, Danger Zone, Webhooks, Resource Operations (the shared Project\Shared\* panels as
 * they apply to services). General (StackForm + resource cards), Environment Variables,
 * Persistent Storages, and Scheduled Tasks stay on the Livewire shell — same route-name
 * split as Phase 54's Database Configuration cut.
 *
 * Same faithful-port notes as ProjectDatabaseConfigurationController where shared, plus:
 * - The service branch of the original ResourceOperations::cloneTo() contained per-child
 *   volume-renaming/VolumeCloneJob loops iterating `$new_resource->applications()` — a
 *   HasMany *relation object*, which foreach silently iterates zero times (and a fresh
 *   replica has no children rows anyway; `parse()` at the end is what creates them). Those
 *   confirmed-dead loops are not ported; clone is replicate + tags + scheduled tasks +
 *   env vars + parse().
 * - Webhooks for a service is the read-only deploy webhook URL — the manual git-webhook
 *   section is application-only.
 */
class ProjectServiceConfigurationController extends Controller
{
    use ManagesResourceDanger;
    use ManagesResourceEnvironmentVariables;
    use ManagesResourceOperations;
    use ManagesResourceScheduledTasks;
    use ManagesResourceStorages;
    use ManagesResourceTags;
    use ManagesResourceWebhooks;
    use NormalizesServiceFqdns;
    use ResolvesProjectResources;

    public function show(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): Response|RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }
        $this->authorize('view', $service);

        // Both scheduled-tasks route names (the `.show` list and the bare /tasks/{task_uuid}
        // detail) render the same tab; the detail view is selected by the task_uuid param.
        $tab = str((string) $request->route()->getName())->after('project.service.')->before('.show')->value();
        $parameters = compact('project_uuid', 'environment_uuid', 'service_uuid');

        $props = [
            'tab' => $tab,
            'service' => [
                'uuid' => $service->uuid,
                'name' => $service->name,
                'status' => $service->status,
                'isDeployable' => $service->is_deployable,
            ],
            'parameters' => $parameters,
            'documentationUrl' => $service->documentation(),
            'canUpdate' => auth()->user()->can('update', $service),
            'tabs' => $this->tabLinks($parameters),
            'urls' => [
                'start' => route('project.logs.service.start', $parameters),
                'forceDeploy' => route('project.logs.service.force-deploy', $parameters),
                'restart' => route('project.logs.service.restart', $parameters),
                'stop' => route('project.logs.service.stop', $parameters),
                'checkStatus' => route('project.logs.service.check-status', $parameters),
            ],
        ];

        return Inertia::render('Project/Service/Configuration', [...$props, ...$this->tabProps($tab, $service, $parameters)]);
    }

    public function storeTag(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        return $this->storeResourceTag($request, $service);
    }

    public function destroyTag(string $project_uuid, string $environment_uuid, string $service_uuid, string $tag_id): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        return $this->destroyResourceTag($service, $tag_id);
    }

    public function move(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        return $this->moveResourceToEnvironment($request, $service, 'project.service.configuration', compact('project_uuid', 'environment_uuid', 'service_uuid'), 'service_uuid');
    }

    public function clone(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }
        $this->authorize('update', $service);

        $validated = Validator::make($request->all(), [
            'destination_id' => 'required|integer',
        ])->validate();

        $newDestination = StandaloneDocker::ownedByCurrentTeam()->find($validated['destination_id'])
            ?? SwarmDocker::ownedByCurrentTeam()->find($validated['destination_id']);
        if (! $newDestination) {
            return back()->withErrors(['destination_id' => 'Destination not found.']);
        }

        $uuid = (string) new Cuid2;
        $clone = $service->replicate(['id', 'created_at', 'updated_at'])->fill([
            'uuid' => $uuid,
            'name' => $service->name.'-clone-'.$uuid,
            'destination_id' => $newDestination->id,
            'destination_type' => $newDestination->getMorphClass(),
            'server_id' => $newDestination->server_id,
        ]);
        $clone->save();

        foreach ($service->tags as $tag) {
            $clone->tags()->attach($tag->id);
        }

        foreach ($service->scheduled_tasks()->get() as $task) {
            $task->replicate(['id', 'created_at', 'updated_at'])->fill([
                'uuid' => (string) new Cuid2,
                'service_id' => $clone->id,
                'team_id' => currentTeam()->id,
            ])->save();
        }

        foreach ($service->environment_variables()->get() as $variable) {
            $variable->replicate(['id', 'created_at', 'updated_at'])->fill([
                'resourceable_id' => $clone->id,
                'resourceable_type' => $clone->getMorphClass(),
            ])->save();
        }

        $clone->parse();

        return redirect()->to(route('project.service.configuration', [
            'project_uuid' => $project_uuid,
            'environment_uuid' => $environment_uuid,
            'service_uuid' => $clone->uuid,
        ]).'#resource-operations');
    }

    public function destroy(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        return $this->destroyResource($request, $service, compact('project_uuid', 'environment_uuid'));
    }

    public function storeEnv(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        return $this->envStore($request, $service);
    }

    public function updateEnv(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $env_id): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        return $this->envUpdate($request, $service, $env_id);
    }

    public function lockEnv(string $project_uuid, string $environment_uuid, string $service_uuid, string $env_id): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        return $this->envLock($service, $env_id);
    }

    public function destroyEnv(string $project_uuid, string $environment_uuid, string $service_uuid, string $env_id): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        return $this->envDestroy($service, $env_id);
    }

    public function bulkUpdateEnvs(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        return $this->envBulkUpdate($request, $service);
    }

    public function storagesVolumeUpdate(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $volume_id): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        return $this->updateStorageVolume($request, $service, $this->resolveOwnedVolume($service, $volume_id));
    }

    public function storagesVolumeDestroy(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $volume_id): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        return $this->destroyStorageVolume($request, $service, $this->resolveOwnedVolume($service, $volume_id));
    }

    public function storagesFileUpdate(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $file_id): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        return $this->updateStorageFile($request, $service, $this->resolveOwnedFileVolume($service, $file_id));
    }

    public function storagesFileLoad(string $project_uuid, string $environment_uuid, string $service_uuid, string $file_id): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        return $this->loadStorageFile($service, $this->resolveOwnedFileVolume($service, $file_id));
    }

    public function storagesFileConvert(string $project_uuid, string $environment_uuid, string $service_uuid, string $file_id): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        return $this->convertStorageFile($service, $this->resolveOwnedFileVolume($service, $file_id));
    }

    public function storagesFileDestroy(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $file_id): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        return $this->destroyStorageFile($request, $service, $this->resolveOwnedFileVolume($service, $file_id));
    }

    /**
     * Port of StackForm::submit() (also reached by the Edit Compose modal's Save, the
     * original's `saveCompose` event): validate, guard against command injection, then
     * save + saveExtraFields + parse atomically, writing compose configs after commit.
     */
    public function updateGeneral(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }
        $this->authorize('update', $service);

        $extraFields = $this->flattenedExtraFields($service);
        $fieldRules = [];
        foreach ($extraFields as $key => $field) {
            $fieldRules["fields.{$key}"] = data_get($field, 'rules', 'nullable');
        }

        $validated = Validator::make($request->all(), [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'dockerComposeRaw' => 'required|string',
            'fields' => 'nullable|array',
            ...$fieldRules,
        ], array_merge(ValidationPatterns::combinedMessages(), [
            'name.required' => 'The Name field is required.',
            'dockerComposeRaw.required' => 'The Docker Compose Raw field is required.',
        ]))->validate();

        try {
            validateDockerComposeForInjection($validated['dockerComposeRaw']);

            DB::transaction(function () use ($service, $validated, $extraFields) {
                $service->name = $validated['name'];
                $service->description = $validated['description'] ?? null;
                $service->docker_compose_raw = $validated['dockerComposeRaw'];
                $service->save();

                $fields = collect((array) ($validated['fields'] ?? []))
                    ->filter(fn ($value, $key) => $extraFields->has($key))
                    ->map(fn ($value, $key) => ['key' => $key, 'value' => $value]);
                $service->saveExtraFields($fields);

                $service->parse();
            });

            $service->refresh();
            // The original pushed compose configs unconditionally and let SSH failures
            // surface as flash errors; guarding on isFunctional() (the Phase 46 precedent)
            // skips the doomed push for unreachable servers — the DB save above stands
            // either way, matching the original's committed-then-push ordering.
            if ($service->server?->isFunctional()) {
                $service->saveComposeConfigs();
            }

            return back()->with('success', 'Service saved.');
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in updateGeneral().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        } finally {
            if (is_null($service->config_hash)) {
                $service->isConfigurationChanged(true);
            }
        }
    }

    /**
     * Port of the two instant-save checkboxes: StackForm's Connect To Predefined Network
     * and EditCompose's Escape special characters in labels.
     */
    public function updateGeneralSettings(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }
        $this->authorize('update', $service);

        Validator::make($request->all(), [
            'connectToDockerNetwork' => 'nullable|boolean',
            'isContainerLabelEscapeEnabled' => 'nullable|boolean',
        ])->validate();

        if ($request->has('connectToDockerNetwork')) {
            $service->connect_to_docker_network = $request->boolean('connectToDockerNetwork');
        }
        if ($request->has('isContainerLabelEscapeEnabled')) {
            $service->is_container_label_escape_enabled = $request->boolean('isContainerLabelEscapeEnabled');
        }
        $service->save();

        return back()->with('success', 'Service settings saved.');
    }

    /** Port of EditCompose::validateCompose() — runs `docker compose config` on the server over SSH. */
    public function validateCompose(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }
        $this->authorize('update', $service);

        $validated = Validator::make($request->all(), [
            'dockerComposeRaw' => 'required|string',
        ])->validate();

        $isValid = validateComposeFile($validated['dockerComposeRaw'], $service->server_id);
        if ($isValid !== 'OK') {
            return back()->with('error', "Invalid docker-compose file.\n{$isValid}");
        }

        return back()->with('success', 'Docker compose is valid.');
    }

    /**
     * Port of EditDomain::submit() for the resource cards' Edit Domains modal. Unlike the
     * sibling settings-page endpoint (ProjectServiceResourceController::updateApplication),
     * this re-parses the whole service after saving, as the original did.
     */
    public function updateChildDomain(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        $application = $service->applications()->findOrFail((int) $request->route('application_id'));
        $this->authorize('update', $application);

        $validated = Validator::make($request->all(), [
            'fqdn' => ['nullable', 'string'],
            'force_save_domains' => ['boolean'],
            'force_remove_port' => ['boolean'],
        ])->validate();

        try {
            $fqdn = $this->normalizeFqdn((string) ($validated['fqdn'] ?? ''));
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in updateChildDomain().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }
        $warning = $fqdn ? sslipDomainWarning($fqdn) : false;

        $application->fqdn = $fqdn;

        if (! $request->boolean('force_save_domains')) {
            $result = checkDomainUsage(resource: $application);
            if ($result['hasConflicts']) {
                return back()->with(['domainConflicts' => $result['conflicts'], 'showDomainConflictModal' => true]);
            }
        }

        if (! $request->boolean('force_remove_port')) {
            $requiredPort = $application->getRequiredPort();
            if ($requiredPort !== null && $this->fqdnsMissingPort($fqdn)) {
                return back()->with(['requiredPort' => $requiredPort, 'showPortWarningModal' => true]);
            }
        }

        $application->save();
        updateCompose($application);
        $service->parse();

        if (str($application->fqdn)->contains(',')) {
            return back()->with('warning', 'Some services do not support multiple domains, which can lead to problems and is NOT RECOMMENDED. Only use multiple domains if you know what you are doing.');
        }
        if ($warning) {
            return back()->with('warning', __('warning.sslipdomain'));
        }

        return back()->with('success', 'Service saved.');
    }

    /** Port of ResourceCard::restart() — restarts a single stack child (application or database). */
    public function restartChild(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }
        $this->authorize('update', $service);

        $childUuid = (string) $request->route('child_uuid');
        $child = $service->applications()->whereUuid($childUuid)->first();
        $isApplication = $child !== null;
        $child ??= $service->databases()->whereUuid($childUuid)->first();
        abort_if(! $child, 404);

        try {
            $child->restart();
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in restartChild().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $isApplication
            ? 'Service application restarted successfully.'
            : 'Service database restarted successfully.');
    }

    public function scheduledTaskStore(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        return $this->storeScheduledTask($request, $service);
    }

    public function scheduledTaskUpdate(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        return $this->updateScheduledTask($request, $service, $this->resolveOwnedScheduledTask($service, (string) $request->route('task_uuid')));
    }

    public function scheduledTaskToggle(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        return $this->toggleScheduledTask($service, $this->resolveOwnedScheduledTask($service, (string) $request->route('task_uuid')));
    }

    public function scheduledTaskExecute(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        return $this->executeScheduledTaskNow($service, $this->resolveOwnedScheduledTask($service, (string) $request->route('task_uuid')));
    }

    public function scheduledTaskDestroy(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }

        $parameters = compact('project_uuid', 'environment_uuid', 'service_uuid');

        return $this->destroyScheduledTask($service, $this->resolveOwnedScheduledTask($service, (string) $request->route('task_uuid')), 'project.service', $parameters);
    }

    public function scheduledTaskDownload(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): StreamedResponse|RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }
        $this->authorize('view', $service);

        return $this->downloadScheduledTaskLogs(
            $this->resolveOwnedScheduledTask($service, (string) $request->route('task_uuid')),
            (int) $request->route('execution_id'),
        );
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function tabProps(string $tab, Service $service, array $parameters): array
    {
        return match ($tab) {
            'configuration' => $this->generalTabProps($service, $parameters),
            'environment-variables' => $this->environmentVariablesTabProps($service, $parameters, 'project.service'),
            'storages' => $this->storagesTabProps($service, $parameters, 'project.service'),
            'scheduled-tasks' => $this->scheduledTasksTabProps($service, $parameters, 'project.service', request()->route('task_uuid')),
            'tags' => $this->tagsTabProps($service, $parameters, 'project.service'),
            'danger' => $this->dangerTabProps($service, $parameters, 'project.service'),
            'webhooks' => $this->webhooksTabProps($service, $parameters, 'project.service'),
            'resource-operations' => $this->resourceOperationsTabProps($service, $parameters, 'project.service'),
            default => [],
        };
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function generalTabProps(Service $service, array $parameters): array
    {
        $environment = $service->environment;
        $server = $service->destination?->server;

        $childCard = function ($child, bool $isApplication) use ($parameters) {
            return [
                'uuid' => $child->uuid,
                'name' => str($child->human_name ?: $child->name)->headline()->value(),
                'image' => $child->image,
                'description' => $child->description,
                'status' => $child->status,
                'statusFormatted' => formatContainerStatus($child->status),
                'isApplication' => $isApplication,
                'fqdn' => $isApplication ? $child->fqdn : null,
                'configurationRequired' => (bool) $child->configuration_required,
                'showBackups' => ! $isApplication && ($child->isBackupSolutionAvailable() || $child->is_migrated),
                'urls' => [
                    'settings' => route('project.service.index', [...$parameters, 'stack_service_uuid' => $child->uuid]),
                    'backups' => route('project.service.database.backups', [...$parameters, 'stack_service_uuid' => $child->uuid]),
                    'restart' => route('project.service.child.restart', [...$parameters, 'child_uuid' => $child->uuid]),
                    'domain' => $isApplication ? route('project.service.child.domain', [...$parameters, 'application_id' => $child->id]) : null,
                ],
            ];
        };

        return [
            'stackForm' => [
                'name' => $service->name,
                'description' => $service->description,
                'dockerComposeRaw' => $service->docker_compose_raw,
                'dockerCompose' => $service->docker_compose,
                'connectToDockerNetwork' => (bool) $service->connect_to_docker_network,
                'isContainerLabelEscapeEnabled' => (bool) $service->is_container_label_escape_enabled,
                'composeParsingVersion' => isDev() ? $service->compose_parsing_version : null,
                'canValidateCompose' => blank($service->service_type),
                'fields' => $this->flattenedExtraFields($service)->map(fn (array $field, string $key) => [
                    'key' => $key,
                    'serviceName' => $field['serviceName'],
                    'name' => $field['name'],
                    'value' => $field['value'],
                    'isPassword' => $field['isPassword'],
                    'required' => str((string) $field['rules'])->contains('required'),
                    'customHelper' => $field['customHelper'] ?: null,
                ])->values(),
            ],
            'resources' => $service->applications->sort()->map(fn ($app) => $childCard($app, true))
                ->concat($service->databases->sort()->map(fn ($db) => $childCard($db, false)))
                ->values(),
            'resourceDetails' => [
                'resource' => ['name' => $service->name, 'uuid' => $service->uuid],
                'environment' => ['name' => $environment?->name, 'uuid' => $environment?->uuid],
                'project' => ['name' => $environment?->project?->name, 'uuid' => $environment?->project?->uuid],
                'server' => $server ? ['name' => $server->name, 'uuid' => $server->uuid] : null,
                'stackApplications' => $service->applications->map(fn ($app) => ['name' => $app->human_name ?: $app->name, 'uuid' => $app->uuid])->values(),
                'stackDatabases' => $service->databases->map(fn ($db) => ['name' => $db->human_name ?: $db->name, 'uuid' => $db->uuid])->values(),
            ],
            'generalUrls' => [
                'update' => route('project.service.general.update', $parameters),
                'settings' => route('project.service.general.settings', $parameters),
                'validateCompose' => route('project.service.general.validate-compose', $parameters),
            ],
        ];
    }

    /**
     * Mirrors StackForm::mount()'s field prep: flatten Service::extraFields()'s
     * per-service grouping into one collection keyed by env-var key, password fields
     * sorted last within each service group.
     *
     * @return \Illuminate\Support\Collection<string, array{serviceName: string, name: string, value: mixed, isPassword: bool, rules: string, customHelper: mixed}>
     */
    private function flattenedExtraFields(Service $service): \Illuminate\Support\Collection
    {
        $fields = collect([]);
        foreach ($service->extraFields() as $serviceName => $groupFields) {
            foreach ($groupFields as $fieldName => $field) {
                $key = data_get($field, 'key');
                $fields->put($key, [
                    'serviceName' => $serviceName,
                    'key' => $key,
                    'name' => $fieldName,
                    'value' => data_get($field, 'value'),
                    'isPassword' => (bool) data_get($field, 'isPassword', false),
                    'rules' => data_get($field, 'rules', 'nullable'),
                    'customHelper' => data_get($field, 'customHelper', false),
                ]);
            }
        }

        return $fields->groupBy('serviceName')->map(function ($group) {
            return $group->sortBy(fn ($field) => $field['isPassword'] ? 1 : 0)
                ->mapWithKeys(fn ($field) => [$field['key'] => $field]);
        })->flatMap(fn ($group) => $group);
    }

    /**
     * The `key` lets the page mark a link active by the current tab prop rather than by
     * exact URL, so the task detail page (/tasks/{task_uuid}) still highlights Scheduled
     * Tasks — the Livewire sidebar's startsWith() behavior.
     *
     * @param  array<string, string>  $parameters
     * @return array<int, array{key: string, label: string, href: string}>
     */
    private function tabLinks(array $parameters): array
    {
        return [
            ['key' => 'configuration', 'label' => 'General', 'href' => route('project.service.configuration', $parameters)],
            ['key' => 'environment-variables', 'label' => 'Environment Variables', 'href' => route('project.service.environment-variables', $parameters)],
            ['key' => 'storages', 'label' => 'Persistent Storages', 'href' => route('project.service.storages', $parameters)],
            ['key' => 'scheduled-tasks', 'label' => 'Scheduled Tasks', 'href' => route('project.service.scheduled-tasks.show', $parameters)],
            ['key' => 'webhooks', 'label' => 'Webhooks', 'href' => route('project.service.webhooks', $parameters)],
            ['key' => 'resource-operations', 'label' => 'Resource Operations', 'href' => route('project.service.resource-operations', $parameters)],
            ['key' => 'tags', 'label' => 'Tags', 'href' => route('project.service.tags', $parameters)],
            ['key' => 'danger', 'label' => 'Danger Zone', 'href' => route('project.service.danger', $parameters)],
        ];
    }
}
