<?php

declare(strict_types=1);

use App\Jobs\CleanupStaleMultiplexedConnections;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('removes a mux file instead of crashing when its content is unreadable', function () {
    // Regression test: Storage::get() returns null (not a thrown exception) when a file
    // can't be read - e.g. a real race where the mux socket file listed by files() is torn
    // down by a concurrent SSH connection close before this loop reaches it. The old code
    // passed that null straight into substr(), which fatally TypeErrors under strict_types=1
    // ("substr(): Argument #1 ($string) must be of type string, null given") - crashing the
    // whole job and skipping every remaining mux file plus the 3 other cleanup steps that
    // run after it in handle().
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $muxFile = 'mux-'.$server->uuid;

    $diskMock = Mockery::mock();
    $diskMock->shouldReceive('files')->andReturn([$muxFile]);
    $diskMock->shouldReceive('get')->with($muxFile)->andReturn(null);
    Storage::shouldReceive('disk')->with('ssh-mux')->andReturn($diskMock);

    Process::fake([
        'ssh -O check *' => Process::result(exitCode: 0),
    ]);

    Log::shouldReceive('info')
        ->once()
        ->with('Stale mux file detected (dry-run, not removed)', [
            'file' => $muxFile,
            'reason' => 'content_unreadable',
        ]);

    expect(fn () => (new CleanupStaleMultiplexedConnections)->handle())->not->toThrow(\TypeError::class);
});
