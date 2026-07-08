<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Server;
use Illuminate\Routing\Controller as BaseController;
use Inertia\Inertia;
use Inertia\Response;

class SharedVariablesController extends BaseController
{
    public function index(): Response
    {
        return Inertia::render('SharedVariables/Index', [
            'links' => [
                [
                    'href' => route('shared-variables.team.index'),
                    'title' => 'Team wide',
                    'description' => 'Usable for all resources in a team.',
                ],
                [
                    'href' => route('shared-variables.project.index'),
                    'title' => 'Project wide',
                    'description' => 'Usable for all resources in a project.',
                ],
                [
                    'href' => route('shared-variables.environment.index'),
                    'title' => 'Environment wide',
                    'description' => 'Usable for all resources in an environment.',
                ],
                [
                    'href' => route('shared-variables.server.index'),
                    'title' => 'Server wide',
                    'description' => 'Usable for all resources in a server.',
                ],
            ],
        ]);
    }

    public function environment(): Response
    {
        $projects = Project::ownedByCurrentTeamCached()->map(fn ($project) => [
            'name' => $project->name,
            'description' => $project->description,
            'environments' => $project->environments->map(fn ($environment) => [
                'name' => $environment->name,
                'description' => $environment->description,
                'href' => route('shared-variables.environment.show', [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $environment->uuid,
                ]),
            ]),
        ]);

        return Inertia::render('SharedVariables/Environment/Index', [
            'projects' => $projects,
        ]);
    }

    public function project(): Response
    {
        $projects = Project::ownedByCurrentTeamCached()->map(fn ($project) => [
            'name' => $project->name,
            'description' => $project->description,
            'href' => route('shared-variables.project.show', ['project_uuid' => $project->uuid]),
        ]);

        return Inertia::render('SharedVariables/Project/Index', [
            'projects' => $projects,
        ]);
    }

    public function server(): Response
    {
        $servers = Server::ownedByCurrentTeamCached()->map(fn ($server) => [
            'name' => $server->name,
            'description' => $server->description,
            'href' => route('shared-variables.server.show', ['server_uuid' => $server->uuid]),
        ]);

        return Inertia::render('SharedVariables/Server/Index', [
            'servers' => $servers,
        ]);
    }
}
