<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// Throwaway RSA key pair generated solely for this test fixture, not a real credential.
const SERVER_PRIVATE_KEY_TEST_KEY = <<<'KEY'
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

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the server private key Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    PrivateKey::create(['name' => 'Deploy Key', 'private_key' => SERVER_PRIVATE_KEY_TEST_KEY, 'team_id' => $team->id]);
    PrivateKey::create(['name' => 'Git Key', 'private_key' => generateSSHKey('ed25519')['private'], 'team_id' => $team->id, 'is_git_related' => true]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.private-key', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/PrivateKey/Show')
        ->has('serverNavbar')
        ->has('sidebar')
        ->has('privateKeys', 1)
        ->where('canCreate', true)
        ->where('canUpdate', true)
    );
});

it('rejects using a private key not owned by the current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $otherTeam = Team::factory()->create();
    $foreignKey = PrivateKey::create(['name' => 'Foreign Key', 'private_key' => SERVER_PRIVATE_KEY_TEST_KEY, 'team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.private-key.set', ['server_uuid' => $server->uuid]), [
            'private_key_id' => $foreignKey->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'You are not allowed to use this private key.');
});

it('returns 404 for a server owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.private-key', ['server_uuid' => $server->uuid]));

    $response->assertNotFound();
});

it('creates a private key via the store endpoint in modal mode', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('security.private-key.store'), [
            'name' => 'my-test-key',
            'description' => 'a test key',
            'value' => SERVER_PRIVATE_KEY_TEST_KEY,
            'modal_mode' => true,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Private key created successfully.');
    expect(PrivateKey::where('name', 'my-test-key')->where('team_id', $team->id)->exists())->toBeTrue();
});

it('rejects an invalid private key on the store endpoint', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('security.private-key.store'), [
            'name' => 'my-test-key',
            'value' => 'not-a-real-private-key',
            'modal_mode' => true,
        ]);

    $response->assertSessionHasErrors(['value']);
    expect(PrivateKey::where('name', 'my-test-key')->exists())->toBeFalse();
});

it('generates a new key pair via the generate endpoint', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson(route('security.private-key.generate'), ['type' => 'ed25519']);

    $response->assertOk();
    $response->assertJsonStructure(['name', 'description', 'value', 'publicKey']);
});

it('denies non-admins from creating or generating private keys', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'member']);

    $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('security.private-key.store'), [
            'name' => 'my-test-key',
            'value' => SERVER_PRIVATE_KEY_TEST_KEY,
        ])->assertForbidden();

    $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson(route('security.private-key.generate'), ['type' => 'rsa'])
        ->assertForbidden();
});
