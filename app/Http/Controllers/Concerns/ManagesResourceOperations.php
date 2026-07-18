<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Contracts\StandaloneDatabaseInstance;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The Resource Operations tab's generic half (App\Livewire\Project\Shared\ResourceOperations)
 * — the server/project/environment picker props and the "move to a different environment"
 * action, extracted from ProjectDatabaseConfigurationController and
 * ProjectServiceConfigurationController's byte-identical inline implementations on their third
 * consumer, ProjectApplicationConfigurationController (Phase 63).
 *
 * "Clone" deliberately stays out of this concern and per-controller: each resource type
 * replicates a genuinely different set of child objects (Database: volumes/backups/env vars;
 * Service: replicate + tags + scheduled tasks + env vars + parse(); Application:
 * clone_application(), which already handles settings/tags/scheduled tasks/previews/volumes/
 * file storages/env vars in one place) — forcing them into one shared method would either lose
 * fidelity or require an unreadable amount of per-type branching.
 */
trait ManagesResourceOperations
{
    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function resourceOperationsTabProps(Application|Service|(StandaloneDatabaseInstance&Model) $resource, array $parameters, string $routePrefix): array
    {
        return [
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
            'currentProjectId' => $resource->environment->project->id,
            'currentEnvironmentId' => $resource->environment->id,
            'operationUrls' => [
                'clone' => route("{$routePrefix}.clone", $parameters),
                'move' => route("{$routePrefix}.move", $parameters),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function moveResourceToEnvironment(Request $request, Application|Service|(StandaloneDatabaseInstance&Model) $resource, string $configRouteName, array $parameters, string $uuidParamKey): RedirectResponse
    {
        $this->authorize('update', $resource);

        $validated = Validator::make($request->all(), [
            'environment_id' => 'required|integer',
        ])->validate();

        $newEnvironment = Environment::ownedByCurrentTeam()->findOrFail($validated['environment_id']);
        $resource->update(['environment_id' => $newEnvironment->id]);

        return redirect()->to(route($configRouteName, [
            'project_uuid' => $newEnvironment->project->uuid,
            'environment_uuid' => $newEnvironment->uuid,
            $uuidParamKey => $resource->uuid,
        ]).'#resource-operations');
    }
}
