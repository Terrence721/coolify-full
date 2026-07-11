<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the dashboard with no projects or servers', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('projects', 0)
        ->has('servers', 0)
        ->has('privateKeys', 0)
        ->where('canCreateProject', true)
    );
});

it('lists projects and servers owned by the current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id, 'name' => 'my-project']);
    $server = Server::factory()->create(['team_id' => $team->id, 'name' => 'my-server']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('projects', 1)
        ->where('projects.0.name', 'my-project')
        ->where('projects.0.navigateUrl', route('project.resource.index', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $project->environments->first()->uuid,
        ]))
        ->has('servers', 1)
        ->where('servers.0.name', 'my-server')
        ->where('servers.0.showUrl', route('server.show', ['server_uuid' => $server->uuid]))
    );
});

it('does not list projects or servers owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    Project::factory()->create(['team_id' => $otherTeam->id]);
    Server::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('projects', 0)
        ->has('servers', 0)
    );
});
