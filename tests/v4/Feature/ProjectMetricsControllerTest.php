<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
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
function createMetricsTestChain(Team $team): array
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->first();

    return [$project, $environment, $server, $destination];
}

it('renders the application metrics page with metrics disabled, without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $destination] = createMetricsTestChain($team);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.application.metrics', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => $application->uuid,
        ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Shared/Metrics')
        ->where('resourceType', 'application')
        ->where('isMetricsEnabled', false)
        ->where('isUnavailable', false)
        ->has('dataUrl')
    );
});

it('flags docker-compose applications as unavailable for metrics', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $destination] = createMetricsTestChain($team);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.application.metrics', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => $application->uuid,
        ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->where('isUnavailable', true));
});

it('redirects to the dashboard for a nonexistent application', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment] = createMetricsTestChain($team);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.application.metrics', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => 'does-not-exist',
        ]));

    $response->assertRedirect(route('dashboard'));
});

it('renders the database metrics page with metrics disabled, without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $destination] = createMetricsTestChain($team);
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
        ->get(route('project.database.metrics', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'database_uuid' => $database->uuid,
        ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Shared/Metrics')
        ->where('resourceType', 'database')
        ->where('isMetricsEnabled', false)
    );
});

it('redirects to the dashboard for a nonexistent database', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment] = createMetricsTestChain($team);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.database.metrics', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'database_uuid' => 'does-not-exist',
        ]));

    $response->assertRedirect(route('dashboard'));
});

it('returns null metrics for an application with metrics disabled, without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $destination] = createMetricsTestChain($team);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson(route('project.application.metrics.data', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => $application->uuid,
        ]));

    $response->assertOk();
    $response->assertJson(['cpu' => null, 'memory' => null]);
});

it('404s the application metrics data endpoint for a nonexistent application', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment] = createMetricsTestChain($team);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson(route('project.application.metrics.data', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => 'does-not-exist',
        ]));

    $response->assertStatus(404);
});

it('404s for an application another team owns', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    [$project, $environment, , $destination] = createMetricsTestChain($otherTeam);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.application.metrics', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => $application->uuid,
        ]));

    $response->assertRedirect(route('dashboard'));
});
