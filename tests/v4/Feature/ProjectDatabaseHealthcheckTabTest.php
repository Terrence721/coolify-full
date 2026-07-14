<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function healthTabActingAs(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);

    return $user;
}

function healthTabMakePostgres(Team $team): StandalonePostgresql
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->destinations()->first();

    // refresh() loads the DB column defaults (health_check_enabled true, interval 15, …)
    // the in-memory model doesn't have right after create()
    return StandalonePostgresql::create([
        'name' => 'pg',
        'postgres_password' => 'secret',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ])->refresh();
}

function healthTabParams(StandalonePostgresql $database): array
{
    return [
        'project_uuid' => $database->environment->project->uuid,
        'environment_uuid' => $database->environment->uuid,
        'database_uuid' => $database->uuid,
    ];
}

it('renders the healthcheck tab', function () {
    $team = Team::factory()->create();
    healthTabActingAs($team);
    $database = healthTabMakePostgres($team);

    $response = $this->get(route('project.database.healthcheck', healthTabParams($database)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Database/Configuration')
        ->where('tab', 'healthcheck')
        ->has('healthcheck.enabled')
        ->has('healthcheck.interval')
        ->has('healthcheckUrls.update')
        ->has('healthcheckUrls.toggle')
    );
});

it('saves the healthcheck probe settings', function () {
    $team = Team::factory()->create();
    healthTabActingAs($team);
    $database = healthTabMakePostgres($team);

    $response = $this->patch(route('project.database.healthcheck.update', healthTabParams($database)), [
        'enabled' => true,
        'interval' => 30,
        'timeout' => 10,
        'retries' => 3,
        'startPeriod' => 0,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Health check updated. Restart the database to apply the changes.');
    $database->refresh();
    expect($database->health_check_interval)->toBe(30)
        ->and($database->health_check_timeout)->toBe(10)
        ->and($database->health_check_retries)->toBe(3)
        ->and($database->health_check_start_period)->toBe(0);
});

it('rejects out-of-range probe values', function () {
    $team = Team::factory()->create();
    healthTabActingAs($team);
    $database = healthTabMakePostgres($team);
    $originalInterval = $database->health_check_interval;

    $response = $this->patch(route('project.database.healthcheck.update', healthTabParams($database)), [
        'interval' => 0,
        'timeout' => 5,
        'retries' => 5,
        'startPeriod' => 5,
    ]);

    $response->assertSessionHasErrors('interval');
    expect($database->refresh()->health_check_interval)->toBe($originalInterval);
});

it('toggles the healthcheck flag with the matching message', function () {
    $team = Team::factory()->create();
    healthTabActingAs($team);
    $database = healthTabMakePostgres($team);
    $initial = (bool) $database->health_check_enabled;

    $this->post(route('project.database.healthcheck.toggle', healthTabParams($database)))
        ->assertSessionHas('success', 'Health check '.($initial ? 'disabled' : 'enabled').'. Restart the database to apply the changes.');
    expect((bool) $database->refresh()->health_check_enabled)->toBe(! $initial);

    $this->post(route('project.database.healthcheck.toggle', healthTabParams($database)));
    expect((bool) $database->refresh()->health_check_enabled)->toBe($initial);
});

it('redirects cross-team visitors to the dashboard', function () {
    $teamA = Team::factory()->create();
    $database = healthTabMakePostgres($teamA);
    healthTabActingAs(Team::factory()->create());

    $this->get(route('project.database.healthcheck', healthTabParams($database)))
        ->assertRedirect(route('dashboard'));
});
