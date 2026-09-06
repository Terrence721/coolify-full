<?php

declare(strict_types=1);

use App\Jobs\PushServerUpdateJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

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
    Queue::fake();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'sentinel_token' => $token = Crypt::encrypt(json_encode(['server_uuid' => $server->uuid])),
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->post('/api/v1/sentinel/push', [
            // \xC3\x28 is an invalid two-byte UTF-8 sequence.
            'containers' => [['name' => "bad-\xC3\x28-name", 'state' => 'running']],
        ]);

    $response->assertOk();
    $response->assertJson(['message' => 'ok']);
    Queue::assertPushed(PushServerUpdateJob::class);
});
