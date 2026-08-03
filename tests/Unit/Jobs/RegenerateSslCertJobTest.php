<?php

declare(strict_types=1);

use App\Jobs\RegenerateSslCertJob;
use App\Models\Server;
use App\Models\SslCertificate;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Regression test for a real bug: the per-certificate loop only catches \Exception, but
 * $certificate->server resolves to null once the backing Server row is soft-deleted (the
 * default belongsTo query excludes trashed rows) - calling ->sslCertificates() on that null
 * throws \Error, not \Exception, so the catch block never fires. This job is scheduled
 * twiceDaily() instance-wide with no server_id filter (Kernel.php), so one soft-deleted
 * server anywhere in the instance with a certificate due for renewal crashed the entire job,
 * silently halting SSL renewal checks for every team on every run until someone noticed.
 * Same root shape as the StopApplication/StopDatabase/StopService/RestartDatabase null-server
 * crashes, just \Exception instead of no guard at all - inherited verbatim from the original
 * upstream import.
 */
it('logs and continues instead of crashing when a certificate\'s server has been soft-deleted', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $certificate = SslCertificate::create([
        'ssl_certificate' => 'dummy-cert',
        'ssl_private_key' => 'dummy-key',
        'server_id' => $server->id,
        'common_name' => 'example.test',
        'valid_until' => now()->addDay(),
        'is_ca_certificate' => false,
    ]);

    $server->delete();
    expect($server->fresh()->trashed())->toBeTrue();
    expect($certificate->fresh()->server)->toBeNull();

    Log::shouldReceive('error')
        ->once()
        ->with(Mockery::pattern('/Failed to regenerate SSL certificate/'));

    (new RegenerateSslCertJob(force_regeneration: true))->handle();

    expect(true)->toBeTrue();
});
