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

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

// Throwaway RSA key pair generated solely for this test fixture, not a real credential.
const SERVER_INDEX_TEST_KEY = <<<'KEY'
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

it('renders the server index Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    Server::factory()->create(['team_id' => $team->id, 'name' => 'my-server']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/Index')
        ->has('servers', 1)
        ->where('servers.0.name', 'my-server')
        ->where('canCreate', true)
        ->where('limitReached', false)
    );
});

it('only lists servers owned by the current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    Server::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/Index')
        ->has('servers', 0)
    );
});

it('creates a new server by IP address', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $key = PrivateKey::create([
        'name' => 'Deploy Key',
        'private_key' => SERVER_INDEX_TEST_KEY,
        'team_id' => $team->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.store'), [
            'name' => 'new-server',
            'description' => 'A test server',
            'ip' => '192.0.2.10',
            'user' => 'root',
            'port' => 22,
            'private_key_id' => $key->id,
            'is_build_server' => false,
        ]);

    $server = Server::where('ip', '192.0.2.10')->first();
    expect($server)->not->toBeNull();
    expect($server->name)->toBe('new-server');
    expect($server->team_id)->toBe($team->id);
    $response->assertRedirect(route('server.show', ['server_uuid' => $server->uuid]));
});

it('rejects creating a server with an IP already used by the current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    Server::factory()->create(['team_id' => $team->id, 'ip' => '192.0.2.20']);
    $key = PrivateKey::create([
        'name' => 'Deploy Key',
        'private_key' => SERVER_INDEX_TEST_KEY,
        'team_id' => $team->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.store'), [
            'name' => 'duplicate-ip',
            'ip' => '192.0.2.20',
            'user' => 'root',
            'port' => 22,
            'private_key_id' => $key->id,
            'is_build_server' => false,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'A server with this IP/Domain already exists in your team.');
    expect(Server::where('ip', '192.0.2.20')->count())->toBe(1);
});

it('rejects creating a server without selecting a private key', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.store'), [
            'name' => 'no-key-server',
            'ip' => '192.0.2.30',
            'user' => 'root',
            'port' => 22,
            'private_key_id' => null,
            'is_build_server' => false,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'You must select a private key');
    expect(Server::where('ip', '192.0.2.30')->exists())->toBeFalse();
});
