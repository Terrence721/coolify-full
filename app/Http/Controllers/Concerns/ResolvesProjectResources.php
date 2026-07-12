<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Contracts\StandaloneDatabaseInstance;
use App\Models\Application;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;

/**
 * Resolves an Application/Database/Service scoped by the current team's project/environment
 * hierarchy from route UUIDs, redirecting to the dashboard on any miss. Shared by every
 * controller that owns a project/environment/{resource}_uuid route (originally written for
 * ProjectLogsController, extracted once ExecuteContainerCommandController needed the same
 * lookups).
 */
trait ResolvesProjectResources
{
    private function resolveApplication(string $project_uuid, string $environment_uuid, string $application_uuid): Application|RedirectResponse
    {
        $project = currentTeam()->load(['projects'])->projects->where('uuid', $project_uuid)->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('uuid', $environment_uuid)->first();
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        $application = $environment->applications()->where('uuid', $application_uuid)->first();
        if (! $application) {
            return redirect()->route('dashboard');
        }

        return $application;
    }

    /**
     * @return (Model&StandaloneDatabaseInstance)|RedirectResponse
     */
    private function resolveDatabase(string $project_uuid, string $environment_uuid, string $database_uuid): Model|RedirectResponse
    {
        $project = currentTeam()->load(['projects'])->projects->where('uuid', $project_uuid)->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('uuid', $environment_uuid)->first();
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        $database = $environment->databases()->where('uuid', $database_uuid)->first();
        if (! $database) {
            return redirect()->route('dashboard');
        }

        return $database;
    }

    private function resolveService(string $project_uuid, string $environment_uuid, string $service_uuid): Service|RedirectResponse
    {
        $project = currentTeam()->load(['projects'])->projects->where('uuid', $project_uuid)->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('uuid', $environment_uuid)->first();
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        $service = $environment->services()->whereUuid($service_uuid)->first();
        if (! $service) {
            return redirect()->route('dashboard');
        }

        return $service;
    }
}
