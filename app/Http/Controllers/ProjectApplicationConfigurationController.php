<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesResourceDanger;
use App\Http\Controllers\Concerns\ManagesResourceLimits;
use App\Http\Controllers\Concerns\ManagesResourceOperations;
use App\Http\Controllers\Concerns\ManagesResourceScheduledTasks;
use App\Http\Controllers\Concerns\ManagesResourceTags;
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
 * React port of the first cut into App\Livewire\Project\Application\Configuration (Phase 63)
 * — the shell plus the tabs it shares with the already-fully-converted Database/Service
 * routers, byte-identical enough across all three to extract into shared concerns on this
 * their third consumer: Tags, Danger Zone, Resource Limits, Resource Operations's generic
 * "move" half, and Scheduled Tasks (already Application|Service-typed since Phase 58).
 * "Clone" is Application's own — it delegates to clone_application(), the comprehensive
 * helper already proven by Project\CloneMe, rather than duplicating per-child-type cloning
 * logic inline the way Database's and Service's clone() methods each had to.
 *
 * Still routed to Livewire: General, Advanced, Swarm, Environment Variables, Persistent
 * Storage, Git Source, Servers, Webhooks, Preview Deployments, Healthcheck, Rollback — each
 * either application-only business logic (webhooks' manual git-secrets section, servers' full
 * multi-server Destination behavior) or a large enough unit to deserve its own phase.
 *
 * Known v1 gap: the shell's heading is a minimal name/status readout, not a port of
 * Project\Application\Heading (368 PHP+Blade lines of deploy/restart/force-rebuild/status-
 * polling actions) — that's a substantial prerequisite in its own right, deliberately deferred
 * rather than rushed alongside 5 unrelated tabs.
 */
class ProjectApplicationConfigurationController extends Controller
{
    use AuthorizesRequests;
    use ManagesResourceDanger;
    use ManagesResourceLimits;
    use ManagesResourceOperations;
    use ManagesResourceScheduledTasks;
    use ManagesResourceTags;
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
            'application' => [
                'uuid' => $application->uuid,
                'name' => $application->name,
                'status' => $application->status,
            ],
            'parameters' => $parameters,
            'canUpdate' => auth()->user()->can('update', $application),
            'tabs' => $this->tabLinks($parameters),
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
            default => [],
        };
    }

    /**
     * The `key` lets the page mark a link active by the current tab prop rather than by exact
     * URL, so the task detail page (/tasks/{task_uuid}) still highlights Scheduled Tasks
     * (the Livewire sidebar's startsWith() behavior) — same pattern as the Service router.
     *
     * @param  array<string, string>  $parameters
     * @return array<int, array{key: string, label: string, href: string}>
     */
    private function tabLinks(array $parameters): array
    {
        return [
            ['key' => 'configuration', 'label' => 'General', 'href' => route('project.application.configuration', $parameters)],
            ['key' => 'advanced', 'label' => 'Advanced', 'href' => route('project.application.advanced', $parameters)],
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
