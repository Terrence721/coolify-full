<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\Team;
use App\Services\ServerValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

// Regression coverage for a real bug found 2026-07-30 (code review, issue #70): the same
// "Minimum Docker Engine version X is not installed" wording bug as ValidateServer.php (PR
// #82) and ValidateAndInstallServerJob.php, but written fresh during this fork's own Phase 78
// migration (see this class's own docblock) rather than inherited from upstream - the same
// mistake got copy-pasted into new code instead of questioned.

function serverPassingUpToDockerVersionCheck(): Server
{
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $server = Mockery::mock($server)->makePartial();
    $server->shouldReceive('validateConnection')->andReturn(['uptime' => true, 'error' => null]);
    $server->shouldReceive('validateOS')->andReturn(str('ubuntu debian raspbian pop'));
    $server->shouldReceive('validatePrerequisites')->andReturn(['success' => true, 'missing' => [], 'found' => ['git', 'curl', 'jq']]);
    $server->shouldReceive('validateDockerEngine')->andReturn(true);
    $server->shouldReceive('validateDockerCompose')->andReturn(true);
    $server->shouldReceive('isSwarm')->andReturn(false);
    $server->shouldReceive('validateDockerEngineVersion')->andReturn(false);

    return $server;
}

it('reports the real problem when an outdated but installed docker engine fails validation', function () {
    $server = serverPassingUpToDockerVersionCheck();

    $result = (new ServerValidationService)->validate($server, install: false, attempt: 0);

    expect($result['status'])->toBe('failed');
    expect($result['error'])->toContain('too old');
    $majorRequiredVersion = str(config('constants.docker.minimum_required_version'))->before('.')->value();
    expect($result['error'])->toContain($majorRequiredVersion);
    expect($result['error'])->not->toContain('is not installed');
});
