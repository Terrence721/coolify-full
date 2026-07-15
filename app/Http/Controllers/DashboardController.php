<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $projects = Project::ownedByCurrentTeam()->with('environments')->get();
        $servers = Server::ownedByCurrentTeamCached();
        $privateKeys = PrivateKey::ownedByCurrentTeamCached();

        return Inertia::render('Dashboard', [
            'projects' => $projects->map(fn (Project $project) => [
                'uuid' => $project->uuid,
                'name' => $project->name,
                'description' => $project->description,
                'canUpdate' => auth()->user()?->can('update', $project) ?? false,
                'navigateUrl' => $project->navigateTo(),
                'editUrl' => route('project.edit', ['project_uuid' => $project->uuid]),
                'addResourceUrl' => $project->environments->first()
                    ? route('project.resource.create', [
                        'project_uuid' => $project->uuid,
                        'environment_uuid' => $project->environments->first()->uuid,
                    ])
                    : null,
            ]),
            'servers' => $servers->map(fn (Server $server) => [
                'uuid' => $server->uuid,
                'name' => $server->name,
                'description' => $server->description,
                'isReachable' => (bool) $server->settings->is_reachable,
                'isUsable' => (bool) $server->settings->is_usable,
                'forceDisabled' => (bool) $server->settings->force_disabled,
                'showUrl' => route('server.show', ['server_uuid' => $server->uuid]),
            ]),
            'privateKeys' => $privateKeys->map(fn (PrivateKey $key) => [
                'id' => $key->id,
                'name' => $key->name,
            ]),
            'canCreateProject' => auth()->user()?->can('createAnyResource') ?? false,
            'canCreateServer' => auth()->user()?->can('createAnyResource') ?? false,
            'defaultServerName' => generate_random_name(),
            'defaultPrivateKeyId' => $privateKeys->first()?->id,
            'createProjectUrl' => route('project.store'),
            'createServerUrl' => route('server.store'),
            'createKeyUrl' => route('security.private-key.store'),
            'generateKeyUrl' => route('security.private-key.generate'),
            'onboardingUrl' => route('onboarding'),
        ]);
    }
}
