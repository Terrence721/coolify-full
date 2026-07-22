<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('creates an environment variable with a null value without crashing', function () {
    // set_environment_variables()'s null/empty-string guard used to read
    // `is_null($x) && $x === ''` — a value can never be both at once, so the guard was
    // always false and a null value (e.g. from generateEnvValue()'s unrecognized-command
    // default case) fell through into an unguarded trim(null), a TypeError under
    // strict_types=1. Fixed to `||`. See todo.md/migration doc.
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $environmentVariable = EnvironmentVariable::create([
        'key' => 'SERVICE_UNRECOGNIZED_MAGIC_COMMAND',
        'value' => null,
        'resourceable_type' => get_class($application),
        'resourceable_id' => $application->id,
        'is_preview' => false,
    ]);

    expect($environmentVariable->fresh()->value)->toBeNull();
});

it('reads real_value on a null-value environment variable without crashing', function () {
    // realValue()'s JSON-passthrough check used to call json_validate($real_value) unguarded -
    // a TypeError under strict_types=1 whenever $real_value was null (e.g. a required variable
    // with no default, like a fresh one-click service's API-token placeholder before the user
    // fills it in). Reachable any time a resource's env vars are rendered before every required
    // value has been set. Found via the "+ New" resource wizard smoke test.
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $environmentVariable = EnvironmentVariable::create([
        'key' => 'REQUIRED_NO_DEFAULT',
        'value' => null,
        'resourceable_type' => get_class($application),
        'resourceable_id' => $application->id,
        'is_preview' => false,
    ]);

    expect($environmentVariable->fresh()->real_value)->toBeNull();
});

it('creates an environment variable with an empty-string value without crashing', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $environmentVariable = EnvironmentVariable::create([
        'key' => 'SOME_EMPTY_VALUE',
        'value' => '',
        'resourceable_type' => get_class($application),
        'resourceable_id' => $application->id,
        'is_preview' => false,
    ]);

    expect($environmentVariable->fresh()->value)->toBeNull();
});
