<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

// Server::boot()'s static::created hook auto-creates a default StandaloneDocker (name/network
// both "coolify") for every server, so a fresh usable server already has one destination.
// Project::booted()'s static::created hook auto-creates a "production" Environment.
function makeCloneTestServer(int $teamId): Server
{
    $server = Server::factory()->create(['team_id' => $teamId]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);

    return $server;
}

it('renders the clone Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    makeCloneTestServer($team->id);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.clone-me', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/CloneMe')
        ->where('project.uuid', $project->uuid)
        ->has('destinations', 1)
        ->where('destinations.0.destinationName', 'coolify')
    );
});

it('clones an environment into a new project', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $server = makeCloneTestServer($team->id);
    $destination = $server->destinations()->first();
    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.clone-me.store', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]), [
            'type' => 'project',
            'name' => 'cloned-project',
            'destination_id' => $destination->id,
            'clone_volume_data' => false,
        ]);

    $newProject = Project::where('name', 'cloned-project')->first();
    expect($newProject)->not->toBeNull();
    $newEnvironment = $newProject->environments()->where('name', 'production')->first();
    expect($newEnvironment)->not->toBeNull();
    expect($newEnvironment->applications()->count())->toBe(1);
    $response->assertRedirect(route('project.resource.index', [
        'project_uuid' => $newProject->uuid,
        'environment_uuid' => $newEnvironment->uuid,
    ]));
});

it('clones an environment into a new environment within the same project', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $server = makeCloneTestServer($team->id);
    $destination = $server->destinations()->first();
    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.clone-me.store', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]), [
            'type' => 'environment',
            'name' => 'staging',
            'destination_id' => $destination->id,
            'clone_volume_data' => false,
        ]);

    $newEnvironment = $project->environments()->where('name', 'staging')->first();
    expect($newEnvironment)->not->toBeNull();
    expect($newEnvironment->applications()->count())->toBe(1);
    $response->assertRedirect(route('project.resource.index', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $newEnvironment->uuid,
    ]));
});

it('rejects cloning into a project name that already exists', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $server = makeCloneTestServer($team->id);
    $destination = $server->destinations()->first();
    Project::factory()->create(['team_id' => $team->id, 'name' => 'taken-name']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.clone-me.store', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]), [
            'type' => 'project',
            'name' => 'taken-name',
            'destination_id' => $destination->id,
            'clone_volume_data' => false,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Project with the same name already exists.');
});

it('rejects submission without a destination selected', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.clone-me.store', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]), [
            'type' => 'project',
            'name' => 'no-destination',
            'destination_id' => null,
            'clone_volume_data' => false,
        ]);

    $response->assertSessionHasErrors('destination_id');
});
