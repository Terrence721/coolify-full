<?php

declare(strict_types=1);

use App\Jobs\DeleteResourceJob;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function cleanupStuckedResourcesMakeEnvironment(): array
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();

    return [$team, $environment];
}

it('reports a standalone postgresql with an orphaned destination via the destination check, not a later fallback', function () {
    // Regression test: the destination check used `! $postgresql->destination()` (calling the
    // MorphTo relation *method*, which always returns a truthy query builder object) instead of
    // resolving the actual relation - so this branch could never fire. Critically, a naive
    // "DeleteResourceJob got dispatched" assertion doesn't actually catch this: with an orphaned
    // destination_id, the next check (`destination.server`) independently resolves null too and
    // dispatches anyway, just logging the wrong reason ("without server" instead of "without
    // destination") - masking the bug behind a right-outcome-wrong-reason coincidence. Asserting
    // on the actual echoed message is what distinguishes "the destination check fired" from
    // "a later fallback caught it instead".
    [, $environment] = cleanupStuckedResourcesMakeEnvironment();

    Bus::fake();

    $postgresql = StandalonePostgresql::factory()->create([
        'environment_id' => $environment->id,
        'destination_type' => StandaloneDocker::class,
        'destination_id' => 999999,
    ]);

    ob_start();
    $this->artisan('cleanup:stucked-resources');
    $output = ob_get_clean();

    expect($output)->toContain('Postgresql without destination: '.$postgresql->name);
    Bus::assertDispatched(DeleteResourceJob::class, fn ($job) => $job->resource->is($postgresql));
});

it('reports a standalone redis with an orphaned destination via the destination check, not a later fallback', function () {
    [, $environment] = cleanupStuckedResourcesMakeEnvironment();

    Bus::fake();

    $redis = StandaloneRedis::factory()->create([
        'environment_id' => $environment->id,
        'destination_type' => StandaloneDocker::class,
        'destination_id' => 999999,
    ]);

    ob_start();
    $this->artisan('cleanup:stucked-resources');
    $output = ob_get_clean();

    expect($output)->toContain('Redis without destination: '.$redis->name);
    Bus::assertDispatched(DeleteResourceJob::class, fn ($job) => $job->resource->is($redis));
});

it('does not dispatch DeleteResourceJob for a standalone postgresql with a real destination', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->destinations()->first();

    Bus::fake();

    StandalonePostgresql::factory()->create([
        'environment_id' => $environment->id,
        'destination_type' => $destination->getMorphClass(),
        'destination_id' => $destination->id,
    ]);

    $this->artisan('cleanup:stucked-resources');

    Bus::assertNotDispatched(DeleteResourceJob::class);
});
