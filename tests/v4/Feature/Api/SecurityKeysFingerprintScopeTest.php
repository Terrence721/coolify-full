<?php

declare(strict_types=1);

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
const SECURITY_KEYS_FINGERPRINT_SCOPE_TEST_KEY = <<<'KEY'
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

it('allows a different team to create a private key with the same fingerprint', function () {
    // Team A's key is created directly via the model, not a real HTTP round trip - the
    // model's own saving hook runs identically either way, and this avoids Sanctum's
    // guard caching the first request's resolved user across a second sequential
    // in-test HTTP call (a real, separate Laravel testing gotcha, not a production concern
    // since real requests each get a fresh guard resolution).
    $teamA = Team::factory()->create();
    PrivateKey::create([
        'team_id' => $teamA->id,
        'name' => 'team-a-key',
        'private_key' => SECURITY_KEYS_FINGERPRINT_SCOPE_TEST_KEY,
    ]);

    $teamB = Team::factory()->create();
    $userB = User::factory()->create();
    $tokenB = $this->apiToken($userB, $teamB, ['write'], role: 'admin');

    // apiToken() sets session('currentTeam') as a side effect (needed for
    // User::createToken() to resolve the token's own team_id) - clear it before the
    // real request to accurately simulate the 'api' middleware group, which never runs
    // StartSession in production, so session('currentTeam') is never populated there.
    session()->forget('currentTeam');

    $response = $this->withHeaders($this->apiHeaders($tokenB))->postJson('/api/v1/security/keys', [
        'name' => 'team-b-key',
        'private_key' => SECURITY_KEYS_FINGERPRINT_SCOPE_TEST_KEY,
    ]);

    $response->assertCreated();
});

it('still rejects a duplicate fingerprint within the same team', function () {
    $team = Team::factory()->create();
    PrivateKey::create([
        'team_id' => $team->id,
        'name' => 'first-key',
        'private_key' => SECURITY_KEYS_FINGERPRINT_SCOPE_TEST_KEY,
    ]);

    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');
    session()->forget('currentTeam');

    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/v1/security/keys', [
        'name' => 'second-key',
        'private_key' => SECURITY_KEYS_FINGERPRINT_SCOPE_TEST_KEY,
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('message', 'Private key already exists.');
});
