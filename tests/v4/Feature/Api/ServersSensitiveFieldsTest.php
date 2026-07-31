<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InteractsWithApiV1;

// Regression coverage for a real credential leak: removeSensitiveDataFromSettings() only hid
// sentinel_token - it didn't hide logdrain_axiom_api_key or logdrain_newrelic_license_key, both
// real ServerSetting columns with no $hidden anywhere on the model. Any token with plain 'read'
// ability got both back in plaintext on GET /servers and GET /servers/{uuid}. Confirmed
// empirically before filing: a real ServerSetting row's logdrain keys came back verbatim in a
// real API response with a token scoped to ['read'] only. Same bug class, same fix shape, as
// DatabasesSensitiveFieldsTest.php.

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

function makeApiServerWithSecretSettings(array $secretFields): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update($secretFields);

    return [$team, $user, $server];
}

it('never leaks logdrain credentials into the single-resource API response', function () {
    $secretFields = [
        'logdrain_axiom_api_key' => 'SECRET-AXIOM-API-KEY',
        'logdrain_newrelic_license_key' => 'SECRET-NEWRELIC-LICENSE-KEY',
    ];
    [$team, $user, $server] = makeApiServerWithSecretSettings($secretFields);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/servers/{$server->uuid}");

    $response->assertOk();
    foreach ($secretFields as $secretValue) {
        expect($response->getContent())->not->toContain($secretValue);
    }
});

it('never leaks logdrain credentials into the servers-list API response', function () {
    $secretFields = [
        'logdrain_axiom_api_key' => 'SECRET-AXIOM-API-KEY',
        'logdrain_newrelic_license_key' => 'SECRET-NEWRELIC-LICENSE-KEY',
    ];
    [$team, $user] = makeApiServerWithSecretSettings($secretFields);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/servers');

    $response->assertOk();
    foreach ($secretFields as $secretValue) {
        expect($response->getContent())->not->toContain($secretValue);
    }
});

it('still hides sentinel_token, the field that was already correctly redacted', function () {
    // ServerSetting::booted()'s updated() hook restarts Sentinel whenever sentinel_token
    // changes - fake the queue so that real side effect doesn't run in this test.
    Queue::fake();
    [$team, $user, $server] = makeApiServerWithSecretSettings(['sentinel_token' => 'SECRET-SENTINEL-TOKEN']);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/servers/{$server->uuid}");

    $response->assertOk();
    expect($response->getContent())->not->toContain('SECRET-SENTINEL-TOKEN');
});

it('exposes logdrain credentials only when the token carries read:sensitive', function () {
    $secretFields = [
        'logdrain_axiom_api_key' => 'SECRET-AXIOM-API-KEY',
        'logdrain_newrelic_license_key' => 'SECRET-NEWRELIC-LICENSE-KEY',
    ];
    [$team, $user, $server] = makeApiServerWithSecretSettings($secretFields);
    $token = $this->apiToken($user, $team, ['read', 'read:sensitive']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/servers/{$server->uuid}");

    $response->assertOk();
    $response->assertJsonFragment(['logdrain_axiom_api_key' => 'SECRET-AXIOM-API-KEY']);
    $response->assertJsonFragment(['logdrain_newrelic_license_key' => 'SECRET-NEWRELIC-LICENSE-KEY']);
});
