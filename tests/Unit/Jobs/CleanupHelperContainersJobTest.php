<?php

declare(strict_types=1);

use App\Jobs\CleanupHelperContainersJob;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Fakes\JobsRemoteProcessFake;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once __DIR__.'/../../Support/Fakes/jobs_remote_process_overrides.php';

// Regression coverage for a real bug found 2026-08-01 (code review, issue #70): this job's only
// "is this container still needed?" source is ApplicationDeploymentQueue, so it had no way to
// recognize backup-of-* (DatabaseBackupJob's S3 upload helper) or s3-restore-* (the S3 restore
// flow in ManagesDatabaseImport) containers - both use the same coolify-helper image family but
// are never deployments. Since this job runs for every functional server after *any* resource
// delete anywhere in the instance (CleanupStuckedResources, queued from DeleteResourceJob's
// finally block), an unrelated delete on one team's server could kill a backup upload or restore
// in progress on a completely different team's server.

beforeEach(function () {
    JobsRemoteProcessFake::reset();
});

function makeCleanupServer(): Server
{
    $team = Team::factory()->create();

    return Server::factory()->create(['team_id' => $team->id]);
}

it('skips backup-of- and s3-restore- helper containers, but still removes a genuinely orphaned one', function () {
    $server = makeCleanupServer();

    $containers = json_encode([
        ['ID' => 'aaa111', 'Names' => 'backup-of-'.Str::uuid()],
        ['ID' => 'bbb222', 'Names' => 's3-restore-'.Str::uuid()],
        ['ID' => 'ccc333', 'Names' => 'coolify-helper-orphaned'],
    ]);
    JobsRemoteProcessFake::$outputQueue = [$containers];

    (new CleanupHelperContainersJob($server))->handle();

    $removeCalls = array_values(array_filter(
        JobsRemoteProcessFake::$calls,
        fn ($call) => str_contains((string) ($call[0][0] ?? ''), 'docker container rm -f'),
    ));

    expect($removeCalls)->toHaveCount(1);
    expect($removeCalls[0][0][0])->toContain('ccc333');
});

it('does not remove anything when every matching container is a backup or restore helper', function () {
    $server = makeCleanupServer();

    $containers = json_encode([
        ['ID' => 'aaa111', 'Names' => 'backup-of-'.Str::uuid()],
        ['ID' => 'bbb222', 'Names' => 's3-restore-'.Str::uuid()],
    ]);
    JobsRemoteProcessFake::$outputQueue = [$containers];

    (new CleanupHelperContainersJob($server))->handle();

    $removeCalls = array_filter(
        JobsRemoteProcessFake::$calls,
        fn ($call) => str_contains((string) ($call[0][0] ?? ''), 'docker container rm -f'),
    );

    expect($removeCalls)->toBeEmpty();
});
