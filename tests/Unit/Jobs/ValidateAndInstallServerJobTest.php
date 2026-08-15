<?php

declare(strict_types=1);

use App\Jobs\ValidateAndInstallServerJob;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

// Regression coverage for a real bug found 2026-07-30 (code review, issue #70): the Docker-
// version-check failure message said "Minimum Docker Engine version X is not installed" even
// though Docker was already confirmed installed a few lines above - the job's own internal log
// line correctly called it "Docker version not sufficient", but the user-facing message didn't
// say the same thing.

it('reports the real problem when an outdated but installed docker engine fails validation', function () {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $server = Mockery::mock($server)->makePartial();
    $server->shouldReceive('validateConnection')->andReturn(['uptime' => true, 'error' => null]);
    $server->shouldReceive('validateOS')->andReturn(str('ubuntu debian raspbian pop'));
    $server->shouldReceive('validatePrerequisites')->andReturn(['success' => true, 'missing' => [], 'found' => ['git', 'curl', 'jq']]);
    $server->shouldReceive('validateDockerEngine')->andReturn(true);
    $server->shouldReceive('validateDockerCompose')->andReturn(true);
    $server->shouldReceive('validateDockerEngineVersion')->andReturn(false);

    // update() on a Mockery partial mock of an existing Eloquent instance doesn't
    // actually persist (confirmed directly - even the job's own first is_validating=true
    // call silently no-ops), so this captures the call's arguments instead of relying on
    // real DB persistence.
    $updateCalls = [];
    $server->shouldReceive('update')->andReturnUsing(function ($attrs) use (&$updateCalls) {
        $updateCalls[] = $attrs;

        return true;
    });

    (new ValidateAndInstallServerJob($server))->handle();

    $validationLogsCall = collect($updateCalls)->first(fn ($attrs) => array_key_exists('validation_logs', $attrs));
    expect($validationLogsCall)->not->toBeNull('expected an update() call setting validation_logs');
    expect($validationLogsCall['validation_logs'])->toContain('too old');
    $majorRequiredVersion = str(config('constants.docker.minimum_required_version'))->before('.')->value();
    expect($validationLogsCall['validation_logs'])->toContain($majorRequiredVersion);
    expect($validationLogsCall['validation_logs'])->not->toContain('is not installed');
});
