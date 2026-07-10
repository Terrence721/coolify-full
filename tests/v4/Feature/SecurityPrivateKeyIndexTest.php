<?php

declare(strict_types=1);

use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// Throwaway RSA key pair generated solely for this test fixture, not a real credential.
const SECURITY_PRIVATE_KEY_INDEX_TEST_KEY = <<<'KEY'
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

it('renders the private key index Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    PrivateKey::create([
        'name' => 'Deploy Key',
        'description' => 'Used for deployments',
        'private_key' => SECURITY_PRIVATE_KEY_INDEX_TEST_KEY,
        'team_id' => $team->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('security.private-key.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Security/PrivateKey/Index')
        ->has('privateKeys', 1)
        ->where('privateKeys.0.name', 'Deploy Key')
        ->where('privateKeys.0.isInUse', false)
        ->where('canCreate', true)
    );
});

it('only lists private keys owned by the current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    PrivateKey::create([
        'name' => 'Other Team Key',
        'private_key' => SECURITY_PRIVATE_KEY_INDEX_TEST_KEY,
        'team_id' => $otherTeam->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('security.private-key.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Security/PrivateKey/Index')
        ->has('privateKeys', 0)
    );
});

it('creates a private key via the shared create endpoint', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('security.private-key.store'), [
            'name' => 'New Key',
            'description' => 'A fresh key',
            'value' => SECURITY_PRIVATE_KEY_INDEX_TEST_KEY,
            'modal_mode' => true,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Private key created successfully.');
    expect(PrivateKey::where('name', 'New Key')->where('team_id', $team->id)->exists())->toBeTrue();
});

it('cleans up unused keys without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $unused = PrivateKey::create([
        'name' => 'Unused Key',
        'private_key' => SECURITY_PRIVATE_KEY_INDEX_TEST_KEY,
        'team_id' => $team->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('security.private-key.cleanup'));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Unused keys have been cleaned up.');
    expect(PrivateKey::find($unused->id))->toBeNull();
});

it('forbids a member from creating a private key', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'member']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('security.private-key.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Security/PrivateKey/Index')
        ->where('canCreate', false)
    );
});
