<?php

declare(strict_types=1);

use App\Jobs\PushServerUpdateJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

/**
 * Creates a functional, sentinel-token-bearing server and returns [$server, $token]
 * for a valid Authorization header - the auth prerequisite shared by every test here.
 *
 * Fakes the queue: setting sentinel_token fires ServerSetting's updated() hook, which
 * restarts Sentinel and would otherwise require a real Instance Settings FQDN.
 */
function createFunctionalSentinelServer(): array
{
    Queue::fake();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'sentinel_token' => $token = Crypt::encrypt(json_encode(['server_uuid' => $server->uuid])),
    ]);

    return [$server, $token];
}

// containerStateHash() builds its hash from json_encode($containers) - without
// JSON_INVALID_UTF8_SUBSTITUTE, a container name/state containing invalid UTF-8 makes
// json_encode() return false, and hash('xxh128', false) throws a TypeError under this
// file's declare(strict_types=1), 500ing the whole push instead of just hashing it.
//
// A JSON request body can't carry raw invalid UTF-8 in the first place (json_decode()
// would itself fail on the way in), so this uses a form-encoded body instead - Symfony's
// form-data parser doesn't validate string encoding the way JSON does, which is exactly
// how this reaches containerStateHash() for real (e.g. a client posting as
// multipart/form-data or application/x-www-form-urlencoded instead of JSON).
it('does not crash when a container name contains invalid UTF-8', function () {
    [$server, $token] = createFunctionalSentinelServer();

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->post('/api/v1/sentinel/push', [
            // \xC3\x28 is an invalid two-byte UTF-8 sequence.
            'containers' => [['name' => "bad-\xC3\x28-name", 'state' => 'running']],
        ]);

    $response->assertOk();
    $response->assertJson(['message' => 'ok']);
    Queue::assertPushed(PushServerUpdateJob::class);
});

// Every other rejection branch in push() (missing token, decrypt failure, server not
// found, etc.) calls auditLogWebhookFailure() before returning - a malformed 'containers'
// payload was the one gap, leaving a malfunctioning/malicious Sentinel agent's repeated
// bad requests with no trace in the audit log.
it('logs an audit failure when the containers payload fails validation', function () {
    [$server, $token] = createFunctionalSentinelServer();

    Log::shouldReceive('channel')->with('audit')->andReturnSelf();
    $captured = null;
    Log::shouldReceive('warning')->once()->andReturnUsing(function ($event, $context) use (&$captured) {
        $captured = $context;
    });

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/v1/sentinel/push', [
            'containers' => 'not-an-array',
        ]);

    $response->assertStatus(422);
    expect($captured['reason'])->toBe('validation_failed');
    expect($captured['server_uuid'])->toBe($server->uuid);
});

// push() explicitly fetches ServerSetting for $server, then calls isFunctional() -
// which reads $this->settings internally. Without setRelation(), that's a second,
// separate query for the exact same row on every single Sentinel push (default 60s
// per server).
it('does not run a second server_settings query when checking isFunctional()', function () {
    [$server, $token] = createFunctionalSentinelServer();

    DB::enableQueryLog();

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/v1/sentinel/push', ['containers' => []]);

    $settingsQueries = collect(DB::getQueryLog())
        ->filter(fn ($query) => str_contains($query['query'], 'server_settings'));

    $response->assertOk();
    expect($settingsQueries)->toHaveCount(1);
});
