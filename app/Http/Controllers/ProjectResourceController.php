<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Support\DatabaseEngineRegistry;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ProjectResourceController extends Controller
{
    public function index(string $project_uuid, string $environment_uuid): Response
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $environment = $project->environments()->where('uuid', $environment_uuid)->firstOrFail();

        $applications = $environment->applications()->with(['tags', 'destination.server.settings', 'settings'])->get()->sortBy('name');
        $services = $environment->services()->with(['tags', 'destination.server.settings'])->get()->sortBy('name');
        $databases = collect();
        foreach (DatabaseEngineRegistry::relationNames() as $relationName) {
            $databases = $databases->concat($environment->{$relationName}()->with(['tags', 'destination.server.settings'])->get());
        }
        $databases = $databases->sortBy('name');

        return Inertia::render('Project/Resource/Index', [
            'project' => ['uuid' => $project->uuid, 'name' => $project->name],
            'environment' => [
                'uuid' => $environment->uuid,
                'name' => $environment->name,
                'isEmpty' => $environment->isEmpty(),
                'resourceIndexUrl' => route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]),
            ],
            'allProjects' => Project::ownedByCurrentTeamCached()->map(fn (Project $p) => [
                'uuid' => $p->uuid,
                'name' => $p->name,
                'showUrl' => route('project.show', ['project_uuid' => $p->uuid]),
            ]),
            'allEnvironments' => $project->environments()->with([
                'applications:id,uuid,name,environment_id',
                'services:id,uuid,name,environment_id',
                'postgresqls:id,uuid,name,environment_id',
                'redis:id,uuid,name,environment_id',
                'mongodbs:id,uuid,name,environment_id',
                'mysqls:id,uuid,name,environment_id',
                'mariadbs:id,uuid,name,environment_id',
                'keydbs:id,uuid,name,environment_id',
                'dragonflies:id,uuid,name,environment_id',
                'clickhouses:id,uuid,name,environment_id',
            ])->get()->map(function (Environment $env) use ($project) {
                $envDatabases = collect();
                foreach (DatabaseEngineRegistry::relationNames() as $relationName) {
                    $envDatabases = $envDatabases->concat($env->{$relationName});
                }

                $resources = collect()
                    ->merge($env->applications->map(fn (Application $app) => [
                        'uuid' => $app->uuid,
                        'name' => $app->name,
                        'url' => route('project.application.configuration', [
                            'project_uuid' => $project->uuid,
                            'environment_uuid' => $env->uuid,
                            'application_uuid' => $app->uuid,
                        ]),
                    ]))
                    ->merge($envDatabases->map(fn ($db) => [
                        'uuid' => $db->uuid,
                        'name' => $db->name,
                        'url' => route('project.database.configuration', [
                            'project_uuid' => $project->uuid,
                            'environment_uuid' => $env->uuid,
                            'database_uuid' => $db->uuid,
                        ]),
                    ]))
                    ->merge($env->services->map(fn (Service $svc) => [
                        'uuid' => $svc->uuid,
                        'name' => $svc->name,
                        'url' => route('project.service.configuration', [
                            'project_uuid' => $project->uuid,
                            'environment_uuid' => $env->uuid,
                            'service_uuid' => $svc->uuid,
                        ]),
                    ]))
                    ->sortBy(fn (array $item) => strtolower($item['name']))
                    ->values();

                return [
                    'uuid' => $env->uuid,
                    'name' => $env->name,
                    'resourceIndexUrl' => route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $env->uuid]),
                    'resources' => $resources,
                ];
            }),
            'applications' => $this->toSearchableArray($applications, fn (Application $a) => route('project.application.configuration', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $environment->uuid,
                'application_uuid' => $a->uuid,
            ])),
            'databases' => $this->toSearchableArray($databases, fn ($d) => route('project.database.configuration', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $environment->uuid,
                'database_uuid' => $d->uuid,
            ])),
            'services' => $this->toSearchableArray($services, fn (Service $s) => route('project.service.configuration', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $environment->uuid,
                'service_uuid' => $s->uuid,
            ])),
            'canCreate' => auth()->user()?->can('createAnyResource') ?? false,
            'canDelete' => auth()->user()?->can('delete', $environment) ?? false,
            'projectShowUrl' => route('project.show', ['project_uuid' => $project->uuid]),
            'createUrl' => route('project.resource.create', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]),
            'cloneUrl' => route('project.clone-me', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]),
            'deleteUrl' => route('project.environment.destroy', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]),
        ]);
    }

    /**
     * @param  Collection<int, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    private function toSearchableArray(Collection $items, \Closure $urlResolver): array
    {
        return $items->map(fn ($item) => [
            'uuid' => $item->uuid,
            'name' => $item->name,
            'fqdn' => $item->fqdn ?? null,
            'description' => $item->description ?? null,
            'status' => $item->status ?? '',
            'server_status' => $item->server_status ?? null,
            'hrefLink' => $urlResolver($item),
            'destination' => [
                'server' => [
                    'name' => $item->destination?->server->name ?? 'Unknown',
                ],
            ],
            'tags' => $item->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
            ])->values()->toArray(),
        ])->values()->toArray();
    }
}
