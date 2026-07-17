<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function serviceDatabaseBooleanFlagsMakeDatabase(array $attributes = []): ServiceDatabase
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

    return ServiceDatabase::create(array_merge([
        'name' => 'db',
        'service_id' => $service->id,
    ], $attributes));
}

it('returns real booleans from isGzipEnabled/isStripprefixEnabled/isLogDrainEnabled, not raw SQLite ints', function () {
    // Regression test: none of is_gzip_enabled/is_stripprefix_enabled/is_log_drain_enabled
    // have a boolean cast on this model (unlike ServiceApplication, which already casts the
    // first two), so a fresh-from-database instance reads them back as SQLite's raw int(1)/
    // int(0). Declaring these accessors `: bool` without fixing the casts would throw a
    // TypeError under strict_types=1 the moment a persisted model called them.
    $database = serviceDatabaseBooleanFlagsMakeDatabase([
        'is_gzip_enabled' => true,
        'is_stripprefix_enabled' => false,
        'is_log_drain_enabled' => true,
    ])->fresh();

    expect($database->isGzipEnabled())->toBeTrue();
    expect($database->isStripprefixEnabled())->toBeFalse();
    expect($database->isLogDrainEnabled())->toBeTrue();
});
