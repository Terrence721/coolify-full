<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function serviceApplicationBooleanFlagsMakeApplication(array $attributes = []): ServiceApplication
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->destinations()->first();

    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
    ]);

    return ServiceApplication::create(array_merge([
        'name' => 'app',
        'service_id' => $service->id,
    ], $attributes));
}

it('returns a real boolean from isLogDrainEnabled(), not the raw SQLite int', function () {
    // Regression test: is_log_drain_enabled has no boolean cast on this model, so a
    // fresh-from-database instance reads it back as SQLite's raw int(1)/int(0). Declaring
    // isLogDrainEnabled(): bool without fixing the cast would throw a TypeError under
    // strict_types=1 the moment a persisted (not just newly-instantiated) model called it.
    $application = serviceApplicationBooleanFlagsMakeApplication(['is_log_drain_enabled' => true])->fresh();

    expect($application->isLogDrainEnabled())->toBeTrue();
});
