<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\StandaloneDatabaseInstance;
use App\Http\Controllers\Concerns\BuildsConfigurationCheckerProps;
use App\Http\Controllers\Concerns\ManagesApplicationHeading;
use App\Http\Controllers\Concerns\ResolvesProjectResources;
use App\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * React port of App\Livewire\Project\Shared\Metrics's `project.application.metrics` and
 * `project.database.metrics` routes — a CPU/memory usage chart pair, originally shared by
 * the Application and Database Configuration routers (only these two routes were repointed
 * here, matching the established "split a shared class's routes, keep what's still needed"
 * pattern). `Database\Configuration` is now fully React (Phase 62); `Application\Configuration`
 * is still Livewire.
 */
class ProjectMetricsController extends Controller
{
    use AuthorizesRequests;
    use BuildsConfigurationCheckerProps;
    use ManagesApplicationHeading;
    use ResolvesProjectResources;

    public function application(string $project_uuid, string $environment_uuid, string $application_uuid): Response|RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if ($application instanceof RedirectResponse) {
            return $application;
        }

        $this->authorize('view', $application);

        $server = $application->destination->server;
        $parameters = compact('project_uuid', 'environment_uuid', 'application_uuid');

        return Inertia::render('Project/Shared/Metrics', [
            'resourceType' => 'application',
            'title' => $application->name,
            ...$this->applicationHeadingProps($application, $parameters),
            'configurationChecker' => $this->applicationConfigurationCheckerProps($application),
            'parameters' => $parameters,
            'isUnavailable' => $application->build_pack === 'dockercompose',
            'isMetricsEnabled' => (bool) $server->isMetricsEnabled(),
            'isRunning' => str($application->status)->contains('running'),
            'serverMetricsUrl' => route('server.metrics', ['server_uuid' => $server->uuid]),
            'dataUrl' => route('project.application.metrics.data', $parameters),
            'sidebarFlags' => [
                'isSwarm' => $server->isSwarm(),
                'isGitBased' => $application->git_based(),
                'isDockerImage' => $application->build_pack === 'dockerimage',
                'isDockerCompose' => $application->build_pack === 'dockercompose',
            ],
        ]);
    }

    public function database(string $project_uuid, string $environment_uuid, string $database_uuid): Response|RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if ($database instanceof RedirectResponse) {
            return $database;
        }

        $this->authorize('view', $database);

        $server = $database->destination?->server;
        $parameters = compact('project_uuid', 'environment_uuid', 'database_uuid');

        return Inertia::render('Project/Shared/Metrics', [
            'resourceType' => 'database',
            'title' => $database->name,
            'databaseHeading' => [
                'parameters' => $parameters,
                'dockerCleanupDefault' => true,
                'isFunctional' => (bool) $server?->isFunctional(),
                'isExited' => str($database->status)->startsWith('exited'),
            ],
            'headingUrls' => [
                'start' => route('project.database.start', $parameters),
                'restart' => route('project.database.restart', $parameters),
                'stop' => route('project.database.stop', $parameters),
                'checkStatus' => route('project.database.check-status', $parameters),
            ],
            'configurationChecker' => $this->databaseConfigurationCheckerProps($database),
            'parameters' => $parameters,
            'isUnavailable' => false,
            'isMetricsEnabled' => (bool) $server?->isMetricsEnabled(),
            'isRunning' => str($database->status)->contains('running'),
            'serverMetricsUrl' => $server ? route('server.metrics', ['server_uuid' => $server->uuid]) : null,
            'dataUrl' => route('project.database.metrics.data', $parameters),
            'sidebarFlags' => [
                'canUpdate' => (bool) auth()->user()?->can('update', $database),
            ],
        ]);
    }

    public function applicationData(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): JsonResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if ($application instanceof RedirectResponse) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        $this->authorize('view', $application);

        return $this->metricsData($request, $application);
    }

    public function databaseData(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): JsonResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if ($database instanceof RedirectResponse) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('view', $database);

        return $this->metricsData($request, $database);
    }

    /**
     * @param  (Model&StandaloneDatabaseInstance)|Application  $resource
     */
    private function metricsData(Request $request, Model|Application $resource): JsonResponse
    {
        $interval = (int) $request->query('interval', 5);

        try {
            return response()->json([
                'cpu' => $resource->getCpuMetrics($interval),
                'memory' => $resource->getMemoryMetrics($interval),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
