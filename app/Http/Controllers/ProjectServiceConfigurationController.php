<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesResourceEnvironmentVariables;
use App\Http\Controllers\Concerns\ManagesResourceScheduledTasks;
use App\Http\Controllers\Concerns\ManagesResourceStorages;
use App\Http\Controllers\Concerns\ResolvesProjectResources;
use App\Jobs\DeleteResourceJob;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    use ManagesResourceEnvironmentVariables;
    use ManagesResourceScheduledTasks;
    use ManagesResourceStorages;
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
                'isDeployable' => $service->isDeployable,
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
        $this->authorize('update', $service);

        $validated = Validator::make($request->all(), [
            'tags' => 'required_without:tag_id|nullable|string|min:2',
            'tag_id' => 'required_without:tags|nullable|integer',
        ])->validate();

        if (filled($validated['tag_id'] ?? null)) {
            $tag = Tag::ownedByCurrentTeam()->findOrFail((int) $validated['tag_id']);
            if ($service->tags()->where('id', $tag->id)->exists()) {
                return back()->with('error', "Tag {$tag->name} already added.");
            }
            $service->tags()->attach($tag->id);

            return back()->with('success', 'Tag added.');
        }

        $skipped = [];
        foreach (str($validated['tags'])->trim()->explode(' ') as $name) {
            $name = strip_tags($name);
            if (strlen($name) < 2) {
                $skipped[] = "Tag {$name} is invalid (min length is 2).";

                continue;
            }
            if ($service->tags()->where('name', $name)->exists()) {
                $skipped[] = "Tag {$name} already added.";

                continue;
            }
            $tag = Tag::ownedByCurrentTeam()->where('name', $name)->first()
                ?? Tag::create(['name' => $name, 'team_id' => currentTeam()->id]);
            $service->tags()->attach($tag->id);
        }

        if ($skipped !== []) {
            return back()->with('error', implode(' ', $skipped));
        }

        return back()->with('success', 'Tags added.');
    }

    public function destroyTag(string $project_uuid, string $environment_uuid, string $service_uuid, string $tag_id): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }
        $this->authorize('update', $service);

        $service->tags()->detach($tag_id);
        $tag = Tag::ownedByCurrentTeam()->find($tag_id);
        if ($tag && $tag->applications()->count() == 0 && $tag->services()->count() == 0) {
            $tag->delete();
        }

        return back()->with('success', 'Tag deleted.');
    }

    public function move(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if (! $service instanceof Service) {
            return $service;
        }
        $this->authorize('update', $service);

        $validated = Validator::make($request->all(), [
            'environment_id' => 'required|integer',
        ])->validate();

        $newEnvironment = Environment::ownedByCurrentTeam()->findOrFail($validated['environment_id']);
        $service->update(['environment_id' => $newEnvironment->id]);

        return redirect()->to(route('project.service.configuration', [
            'project_uuid' => $newEnvironment->project->uuid,
            'environment_uuid' => $newEnvironment->uuid,
            'service_uuid' => $service->uuid,
        ]).'#resource-operations');
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

        $validated = Validator::make($request->all(), [
            'password' => 'required|string',
            'delete_volumes' => 'nullable|boolean',
            'delete_connected_networks' => 'nullable|boolean',
            'delete_configurations' => 'nullable|boolean',
            'docker_cleanup' => 'nullable|boolean',
        ])->validate();

        if (! verifyPasswordConfirmation($validated['password'])) {
            return back()->with('error', 'The provided password is incorrect.');
        }

        $this->authorize('delete', $service);

        $service->delete();
        DeleteResourceJob::dispatch(
            $service,
            $request->boolean('delete_volumes'),
            $request->boolean('delete_connected_networks'),
            $request->boolean('delete_configurations'),
            $request->boolean('docker_cleanup'),
        );

        return redirect()->route('project.resource.index', [
            'project_uuid' => $project_uuid,
            'environment_uuid' => $environment_uuid,
        ]);
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
            'environment-variables' => $this->environmentVariablesTabProps($service, $parameters, 'project.service'),
            'storages' => $this->storagesTabProps($service, $parameters, 'project.service'),
            'scheduled-tasks' => $this->scheduledTasksTabProps($service, $parameters, 'project.service', request()->route('task_uuid')),
            'tags' => [
                'tags' => $service->tags->map(fn (Tag $tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'destroyUrl' => route('project.service.tags.destroy', [...$parameters, 'tag_id' => $tag->id]),
                ])->values(),
                'availableTags' => Tag::ownedByCurrentTeam()->get()
                    ->reject(fn (Tag $tag) => $service->tags->contains($tag))
                    ->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name])
                    ->values(),
                'tagsStoreUrl' => route('project.service.tags.store', $parameters),
            ],
            'danger' => [
                'resourceName' => $service->name ?? 'Service',
                'canDelete' => auth()->user()->can('delete', $service),
                'destroyUrl' => route('project.service.destroy', $parameters),
            ],
            'webhooks' => [
                'deployWebhook' => generateDeployWebhook($service),
            ],
            'resource-operations' => [
                'servers' => currentTeam()->servers
                    ->filter(fn ($server) => ! $server->isBuildServer())
                    ->map(fn ($server) => [
                        'id' => $server->id,
                        'name' => $server->name,
                        'ip' => $server->ip,
                        'destinations' => $server->destinations()->map(fn ($destination) => [
                            'id' => $destination->id,
                            'name' => $destination->name,
                        ])->values(),
                    ])->values(),
                'projects' => Project::ownedByCurrentTeamCached()->map(fn (Project $project) => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'environments' => $project->environments->map(fn (Environment $environment) => [
                        'id' => $environment->id,
                        'name' => $environment->name,
                    ])->values(),
                ])->values(),
                'currentProjectId' => $service->environment->project->id,
                'currentEnvironmentId' => $service->environment->id,
                'operationUrls' => [
                    'clone' => route('project.service.clone', $parameters),
                    'move' => route('project.service.move', $parameters),
                ],
            ],
            default => [],
        };
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
