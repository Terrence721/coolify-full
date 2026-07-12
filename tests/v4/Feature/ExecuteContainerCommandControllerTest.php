<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

/**
 * Renamed per this migration's established convention to avoid Pest's global-function-name
 * collision across test files (all Feature test files share one PHP process).
 */
function createCommandTestChain(Team $team): array
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->first();

    return [$project, $environment, $server, $destination];
}

it('lists the synthetic container for a functional swarm server, without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, $server, $destination] = createCommandTestChain($team);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
        'is_swarm_manager' => true,
    ]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.application.command', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => $application->uuid,
        ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Shared/Command')
        ->has('containers', 1)
        ->where('containers.0.name', "{$application->uuid}_{$application->uuid}")
    );
});

it('renders the application command page with no functional server, without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $destination] = createCommandTestChain($team);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.application.command', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => $application->uuid,
        ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Shared/Command')
        ->where('type', 'application')
        ->has('containers', 0)
        ->has('terminalConfig')
        ->has('connectUrl')
    );
});

it('redirects to the dashboard for a nonexistent application', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment] = createCommandTestChain($team);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.application.command', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => 'does-not-exist',
        ]));

    $response->assertRedirect(route('dashboard'));
});

it('renders the database command page with no functional server, without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $destination] = createCommandTestChain($team);
    $database = StandalonePostgresql::create([
        'name' => 'test-postgres',
        'postgres_password' => 'secret',
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'environment_id' => $environment->id,
        'status' => 'exited',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.database.command', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'database_uuid' => $database->uuid,
        ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Shared/Command')
        ->where('type', 'database')
        ->has('containers', 0)
    );
});

it('redirects to the dashboard for a nonexistent database', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment] = createCommandTestChain($team);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.database.command', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'database_uuid' => 'does-not-exist',
        ]));

    $response->assertRedirect(route('dashboard'));
});

it('renders the service command page with no functional server, without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, $server, $destination] = createCommandTestChain($team);
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.service.command', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'service_uuid' => $service->uuid,
        ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Shared/Command')
        ->where('type', 'service')
        ->has('containers', 0)
    );
});

it('redirects to the dashboard for a nonexistent service', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment] = createCommandTestChain($team);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.service.command', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'service_uuid' => 'does-not-exist',
        ]));

    $response->assertRedirect(route('dashboard'));
});

it('renders the server command page showing the not-functional message, without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.command', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/Command')
        ->where('isFunctional', false)
        ->has('terminalConfig')
        ->has('connectUrl')
    );
});

it('forbids the application command page for a non-admin member', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'member']);
    [$project, $environment, , $destination] = createCommandTestChain($team);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.application.command', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => $application->uuid,
        ]));

    $response->assertForbidden();
});

it('rejects an application connect request with no container selected', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $destination] = createCommandTestChain($team);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson(route('project.application.command.connect', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => $application->uuid,
        ]), ['selected_container' => 'default']);

    $response->assertStatus(422);
    $response->assertJson(['error' => 'Please select a container.']);
});

it('rejects an application connect request for a container not in the resolved list, without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $destination] = createCommandTestChain($team);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson(route('project.application.command.connect', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => $application->uuid,
        ]), ['selected_container' => 'not-a-real-container']);

    $response->assertStatus(404);
    $response->assertJson(['error' => 'Container not found.']);
});

it('returns 404 connecting to a server the team does not own', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson(route('server.command.connect', ['server_uuid' => 'does-not-exist']));

    $response->assertStatus(404);
    $response->assertJson(['error' => 'Server not found.']);
});
