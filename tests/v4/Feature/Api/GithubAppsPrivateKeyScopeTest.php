<?php

declare(strict_types=1);

use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

// Throwaway RSA key pair generated solely for this test fixture, not a real credential.
const GITHUB_APPS_PRIVATE_KEY_SCOPE_TEST_KEY = <<<'KEY'
-----BEGIN RSA PRIVATE KEY-----
MIIEpAIBAAKCAQEAtN47DRoydtu3Ko7p41K/oUA06pY8xLpU9wDjxEkk3C4RfACL
GAu2HCSfoB+WwW+mQTg2wu+GJQSQoi+a8w0hFbbUua+XbHVNHgBU5oVXh6eZA1Yk
zRlekfU0axAfPyVvZDhoAd+mu5UbDl9NpscMhbSpDNw3l8WS9VIt6Jnx0K4mTtCf
ZCuHitlzLQuBXQTKTpQo6jmpvRgxuCCWicR3I9NFcpaBZJVgXBz3fNB2LshCFP9l
P1TwEzsY2MxIgn5Us2+hdRO+P8LzRHksr8FjhJfldfnHidz7uIDSuU4Lp0gaXGWV
nbZza6+wOTjBagJcmz1jNT3KiqvL4QxGkQik6QIDAQABAoIBAAXUpjMF4FgKdgJ0
fm4TPTkGm1xTFlXeVeUylIixiyxEYJfOm5DdfZB8XKaN3+vIzlxR/v3wxutZlQvU
jn3vely7V05arpq2bSGehQG0VGjC2Mgb66c8xUxsCwrVMioCsVLhDfcTuEnLr1uo
+dx6lFjub2pC/u3NVq+Jkkj4f7qMB3hzbqkmeyQq/vTzB7i1ddEFyDPelIVvrxbp
wElIrlcLeJuFxQrTV/hxrgWEnvVGmB80lDA0vZ16q2uQJ/PqOZ//QWlCBIeCKD5t
3sMmlbogVSmn/hoAN3Za/amjQx5aZBNxYd+Yy7pun735DmX9aklgn/u1m2pxBvv9
0XMw+9MCgYEA2hwTYPGfOoexXwHzHjHJzDxIdAxJV1eXimleF5GYxMRD9uOUWjPc
fyqbKpJXbCHJm8Zm3EGOvpgugv8Il6T8VNGdghPFnUddbRy+EbiWUusUUPbuc/E1
BSBw2s14LTeBj/2bXyw6BvIp3yj44io2vdPrsB1+E94rZ7btcFOhEDcCgYEA1Enr
6i71QM9VLfbRg/a1NdGcv8fnwI8Q8BKGCNnGNvsO4ZK2VunN1U+Lv1IhamFpIy1w
JPGgFinngzkFszZ3Rx+t7/QgJLQG6AKgGEAGFsRqJXVI3sZtQrGkTKM6yVbF2Vi5
E2hFH695nHT5N93TFfmfVvnbHCKKyYqvCzecI98CgYEAyV6geaG7C9PZ68imCJuZ
H2oMzq/FStGBBPZRO9tdu1UlFp15C2rUScgxaDWiZyAuvhaIQxR30Po5/xGtgix+
F2VMUZslmRcZZ7LgvQW6LCYEJNhGwV7SP8B60VhgewbDJQjVWSJBFMah5/oxBsZI
siwlbv1buMYnNuNKBqn/izMCgYAv7xkT4dKC9c3X+RlJ4NT99/ya2TqdIjDC5Ivb
R8EX/QxZJtWBPn25oqJ9asAc0y34QXRHA0AQgRnDaYa99phsONz/h3ISl4vPq3gW
wa4eSe9l0dvIYameG5prq5fEipFWCFCR70NcajTdfRQg5zeYiKrP6s7sxWftJiFs
OPxKpQKBgQDHMksWTQSjunvD2/o4NYQquSXJvHP9JA7k3n7QgYBSFHmpFOY6xeri
my6RXd8RMIRj/i0/oLTtizy45BqHejnjWHMb2UvXebWHK0yHeC4WNaLaJhvH09UN
4xXL4TqipLiBPWflXdBDOIwdJ20U4Y3PNuVIhbpsWJAPQ1/IaKAryQ==
-----END RSA PRIVATE KEY-----
KEY;

it('rejects a private_key_uuid belonging to another team on create', function () {
    $otherTeam = Team::factory()->create();
    $otherKey = PrivateKey::create([
        'team_id' => $otherTeam->id,
        'name' => 'other-teams-key',
        'private_key' => GITHUB_APPS_PRIVATE_KEY_SCOPE_TEST_KEY,
    ]);

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/v1/github-apps', [
        'name' => 'my-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 1,
        'installation_id' => 2,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'webhook_secret' => 'webhook-secret',
        'private_key_uuid' => $otherKey->uuid,
    ]);

    $response->assertStatus(404);
    $response->assertJsonPath('message', 'Private key not found or does not belong to your team');
});

it('accepts a private_key_uuid belonging to the current team on create', function () {
    $team = Team::factory()->create();
    $key = PrivateKey::create([
        'team_id' => $team->id,
        'name' => 'my-key',
        'private_key' => GITHUB_APPS_PRIVATE_KEY_SCOPE_TEST_KEY,
    ]);

    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/v1/github-apps', [
        'name' => 'my-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 1,
        'installation_id' => 2,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'webhook_secret' => 'webhook-secret',
        'private_key_uuid' => $key->uuid,
    ]);

    $response->assertCreated();
});

it('rejects a private_key_uuid belonging to another team on update', function () {
    $team = Team::factory()->create();
    $githubApp = GithubApp::create([
        'team_id' => $team->id,
        'name' => 'my-github-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
    ]);

    $otherTeam = Team::factory()->create();
    $otherKey = PrivateKey::create([
        'team_id' => $otherTeam->id,
        'name' => 'other-teams-key',
        'private_key' => GITHUB_APPS_PRIVATE_KEY_SCOPE_TEST_KEY,
    ]);

    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/github-apps/{$githubApp->id}", [
        'private_key_uuid' => $otherKey->uuid,
    ]);

    $response->assertStatus(404);
    $response->assertJsonPath('message', 'Private key not found or does not belong to your team');
});

it('attaches a private_key_uuid belonging to the current team on update', function () {
    // PrivateKey::uuid is a Cuid2 (e.g. "tawksfna868syfs1hdkic62e"), not an RFC4122 UUID
    // with hyphens - a real key's own uuid, used here rather than a hand-built string,
    // is what proves this against Laravel's 'uuid' validation rule.
    $team = Team::factory()->create();
    $githubApp = GithubApp::create([
        'team_id' => $team->id,
        'name' => 'my-github-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
    ]);
    $key = PrivateKey::create([
        'team_id' => $team->id,
        'name' => 'my-key',
        'private_key' => GITHUB_APPS_PRIVATE_KEY_SCOPE_TEST_KEY,
    ]);

    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/github-apps/{$githubApp->id}", [
        'private_key_uuid' => $key->uuid,
    ]);

    $response->assertOk();
    expect($githubApp->fresh()->private_key_id)->toBe($key->id);
});

it('does not leak client_secret or webhook_secret in create/update/list responses', function () {
    $team = Team::factory()->create();
    $key = PrivateKey::create([
        'team_id' => $team->id,
        'name' => 'my-key',
        'private_key' => GITHUB_APPS_PRIVATE_KEY_SCOPE_TEST_KEY,
    ]);
    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['read', 'write'], role: 'admin');

    $createResponse = $this->withHeaders($this->apiHeaders($token))->postJson('/api/v1/github-apps', [
        'name' => 'my-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 1,
        'installation_id' => 2,
        'client_id' => 'client-id',
        'client_secret' => 'super-secret-client',
        'webhook_secret' => 'super-secret-webhook',
        'private_key_uuid' => $key->uuid,
    ]);
    $createResponse->assertCreated();
    $createResponse->assertJsonMissing(['client_secret' => 'super-secret-client']);
    $createResponse->assertJsonMissing(['webhook_secret' => 'super-secret-webhook']);

    $githubAppId = $createResponse->json('id');

    $updateResponse = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/github-apps/{$githubAppId}", [
        'name' => 'renamed-app',
    ]);
    $updateResponse->assertOk();
    $updateResponse->assertJsonMissing(['client_secret' => 'super-secret-client']);
    $updateResponse->assertJsonMissing(['webhook_secret' => 'super-secret-webhook']);

    $listResponse = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/github-apps');
    $listResponse->assertOk();
    $listResponse->assertJsonMissing(['client_secret' => 'super-secret-client']);
    $listResponse->assertJsonMissing(['webhook_secret' => 'super-secret-webhook']);
});
