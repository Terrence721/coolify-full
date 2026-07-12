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
 * Renamed per this migration's established convention to avoid Pest's
 * global-function-name collision across test files (all Feature test files share one
 * PHP process — see the Phase 43+ notes on this).
 */
function createLogsTestChain(Team $team): array
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->first();

    return [$project, $environment, $server, $destination];
}

it('renders the application logs page with no functional server, without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $destination] = createLogsTestChain($team);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.application.logs', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => $application->uuid,
        ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Shared/Logs')
        ->where('type', 'application')
        ->has('containerGroups', 0)
    );
});

it('redirects to the dashboard for a nonexistent application', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment] = createLogsTestChain($team);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.application.logs', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => 'does-not-exist',
        ]));

    $response->assertRedirect(route('dashboard'));
});

it('renders the database logs page with no functional server, without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $destination] = createLogsTestChain($team);
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
        ->get(route('project.database.logs', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'database_uuid' => $database->uuid,
        ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Shared/Logs')
        ->where('type', 'database')
        ->has('containerGroups', 0)
    );
});

it('redirects to the dashboard for a nonexistent database', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment] = createLogsTestChain($team);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.database.logs', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'database_uuid' => 'does-not-exist',
        ]));

    $response->assertRedirect(route('dashboard'));
});

it('renders the service logs page with no functional server, without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, $server, $destination] = createLogsTestChain($team);
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.service.logs', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'service_uuid' => $service->uuid,
        ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Shared/Logs')
        ->where('type', 'service')
        ->has('containerGroups', 0)
    );
});

it('redirects to the dashboard for a nonexistent service', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment] = createLogsTestChain($team);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.service.logs', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'service_uuid' => 'does-not-exist',
        ]));

    $response->assertRedirect(route('dashboard'));
});

it('redirects service lifecycle actions to the dashboard for a nonexistent service', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment] = createLogsTestChain($team);

    $params = [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'service_uuid' => 'does-not-exist',
    ];

    $this->actingAs($user)->withSession(['currentTeam' => $team])
        ->post(route('project.logs.service.start', $params))
        ->assertRedirect(route('dashboard'));

    $this->actingAs($user)->withSession(['currentTeam' => $team])
        ->post(route('project.logs.service.check-status', $params))
        ->assertRedirect(route('dashboard'));
});

it('blocks a service restart when a deployment is already in progress, without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, $server, $destination] = createLogsTestChain($team);
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    activity()
        ->performedOn($service)
        ->withProperties(['type_uuid' => $service->uuid, 'status' => 'in_progress'])
        ->log('deploying');

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.logs.service.restart', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'service_uuid' => $service->uuid,
        ]));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'There is a deployment in progress.');
});

it('returns 404 downloading logs for a non-functional server without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.logs.download', ['server_uuid' => $server->uuid]).'?container=some-container');

    $response->assertNotFound();
});
