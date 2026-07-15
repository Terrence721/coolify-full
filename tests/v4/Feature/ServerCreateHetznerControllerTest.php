<?php

declare(strict_types=1);

use App\Models\CloudInitScript;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

// Throwaway RSA key pair generated solely for this test fixture, not a real credential.
const HETZNER_CONTROLLER_TEST_KEY = <<<'KEY'
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

function actingTeamForHetznerTest(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    return [$user, $team];
}

it('renders the hetzner server creation Inertia page', function () {
    [$user, $team] = actingTeamForHetznerTest();
    CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'hetzner', 'token' => 'abc', 'name' => 'My Token']);
    PrivateKey::create(['name' => 'Deploy Key', 'private_key' => HETZNER_CONTROLLER_TEST_KEY, 'team_id' => $team->id]);
    CloudInitScript::create(['team_id' => $team->id, 'name' => 'My Script', 'script' => "#!/bin/bash\necho hi"]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.new.hetzner'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/New/Hetzner')
        ->has('tokens', 1)
        ->has('privateKeys', 1)
        ->has('cloudInitScripts', 1)
        ->has('defaultName')
        ->has('urls')
    );
});

it('does not include tokens or private keys owned by another team', function () {
    [$user, $team] = actingTeamForHetznerTest();
    $otherTeam = Team::factory()->create();
    CloudProviderToken::create(['team_id' => $otherTeam->id, 'provider' => 'hetzner', 'token' => 'abc', 'name' => 'Foreign Token']);
    PrivateKey::create(['name' => 'Foreign Key', 'private_key' => HETZNER_CONTROLLER_TEST_KEY, 'team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.new.hetzner'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('tokens', 0)
        ->has('privateKeys', 0)
    );
});

it('returns hetzner cloud data for a valid team-owned token', function () {
    Http::fake([
        'https://api.hetzner.cloud/v1/locations*' => Http::response([
            'locations' => [['id' => 1, 'name' => 'fsn1']],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
        'https://api.hetzner.cloud/v1/server_types*' => Http::response([
            'server_types' => [['id' => 1, 'name' => 'cx11', 'deprecated' => false]],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
        'https://api.hetzner.cloud/v1/images*' => Http::response([
            'images' => [['id' => 1, 'name' => 'ubuntu-22.04', 'type' => 'system']],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
        'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response([
            'ssh_keys' => [],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
    ]);

    [$user, $team] = actingTeamForHetznerTest();
    $token = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'hetzner', 'token' => 'abc', 'name' => 'My Token']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson(route('server.new.hetzner.data', ['token_id' => $token->id]));

    $response->assertOk();
    $response->assertJson([
        'locations' => [['id' => 1, 'name' => 'fsn1']],
        'serverTypes' => [['id' => 1, 'name' => 'cx11', 'deprecated' => false]],
        'images' => [['id' => 1, 'name' => 'ubuntu-22.04', 'type' => 'system']],
        'sshKeys' => [],
    ]);
});

it('rejects fetching data for a token not owned by the current team', function () {
    [$user, $team] = actingTeamForHetznerTest();
    $otherTeam = Team::factory()->create();
    $foreignToken = CloudProviderToken::create(['team_id' => $otherTeam->id, 'provider' => 'hetzner', 'token' => 'abc', 'name' => 'Foreign']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson(route('server.new.hetzner.data', ['token_id' => $foreignToken->id]));

    $response->assertStatus(422);
    $response->assertJson(['message' => 'Invalid token selected.']);
});

it('creates a server via hetzner cloud and redirects to the server show page', function () {
    Http::fake([
        'https://api.hetzner.cloud/v1/ssh_keys' => Http::response(['ssh_key' => ['id' => 10, 'name' => 'Deploy Key']], 200),
        'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response([
            'ssh_keys' => [],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
        'https://api.hetzner.cloud/v1/servers' => Http::response([
            'server' => ['id' => 555, 'public_net' => ['ipv4' => ['ip' => '203.0.113.5']]],
        ], 200),
    ]);

    [$user, $team] = actingTeamForHetznerTest();
    $token = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'hetzner', 'token' => 'abc', 'name' => 'My Token']);
    $key = PrivateKey::create(['name' => 'Deploy Key', 'private_key' => HETZNER_CONTROLLER_TEST_KEY, 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.new.hetzner.store'), [
            'token_id' => $token->id,
            'private_key_id' => $key->id,
            'location' => 'fsn1',
            'server_type' => 'cx11',
            'image' => 1,
            'name' => 'my-new-server',
            'enable_ipv4' => true,
            'enable_ipv6' => true,
        ]);

    $server = Server::where('name', 'my-new-server')->first();
    expect($server)->not->toBeNull();
    expect($server->ip)->toBe('203.0.113.5');
    expect($server->hetzner_server_id)->toBe(555);
    expect($server->team_id)->toBe($team->id);
    $response->assertRedirect(route('server.show', ['server_uuid' => $server->uuid]));
});

it('saves the cloud-init script as reusable when requested', function () {
    Http::fake([
        'https://api.hetzner.cloud/v1/ssh_keys' => Http::response(['ssh_key' => ['id' => 10, 'name' => 'Deploy Key']], 200),
        'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response([
            'ssh_keys' => [],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
        'https://api.hetzner.cloud/v1/servers' => Http::response([
            'server' => ['id' => 556, 'public_net' => ['ipv4' => ['ip' => '203.0.113.6']]],
        ], 200),
    ]);

    [$user, $team] = actingTeamForHetznerTest();
    $token = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'hetzner', 'token' => 'abc', 'name' => 'My Token']);
    $key = PrivateKey::create(['name' => 'Deploy Key', 'private_key' => HETZNER_CONTROLLER_TEST_KEY, 'team_id' => $team->id]);

    $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.new.hetzner.store'), [
            'token_id' => $token->id,
            'private_key_id' => $key->id,
            'location' => 'fsn1',
            'server_type' => 'cx11',
            'image' => 1,
            'name' => 'my-new-server-2',
            'enable_ipv4' => true,
            'enable_ipv6' => true,
            'cloud_init_script' => "#!/bin/bash\necho hi",
            'save_cloud_init_script' => true,
            'cloud_init_script_name' => 'Reusable Script',
        ]);

    expect(CloudInitScript::where('name', 'Reusable Script')->where('team_id', $team->id)->exists())->toBeTrue();
});

it('rejects an invalid server name', function () {
    [$user, $team] = actingTeamForHetznerTest();
    $token = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'hetzner', 'token' => 'abc', 'name' => 'My Token']);
    $key = PrivateKey::create(['name' => 'Deploy Key', 'private_key' => HETZNER_CONTROLLER_TEST_KEY, 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.new.hetzner.store'), [
            'token_id' => $token->id,
            'private_key_id' => $key->id,
            'location' => 'fsn1',
            'server_type' => 'cx11',
            'image' => 1,
            'name' => 'Not A Valid Hostname!!',
            'enable_ipv4' => true,
            'enable_ipv6' => true,
        ]);

    $response->assertSessionHasErrors('name');
    expect(Server::where('name', 'Not A Valid Hostname!!')->exists())->toBeFalse();
});

it('rejects using a token not owned by the current team when creating a server', function () {
    [$user, $team] = actingTeamForHetznerTest();
    $otherTeam = Team::factory()->create();
    $foreignToken = CloudProviderToken::create(['team_id' => $otherTeam->id, 'provider' => 'hetzner', 'token' => 'abc', 'name' => 'Foreign']);
    $key = PrivateKey::create(['name' => 'Deploy Key', 'private_key' => HETZNER_CONTROLLER_TEST_KEY, 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.new.hetzner.store'), [
            'token_id' => $foreignToken->id,
            'private_key_id' => $key->id,
            'location' => 'fsn1',
            'server_type' => 'cx11',
            'image' => 1,
            'name' => 'my-new-server-3',
            'enable_ipv4' => true,
            'enable_ipv6' => true,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Invalid token selected.');
});

it('rejects using a private key not owned by the current team when creating a server', function () {
    [$user, $team] = actingTeamForHetznerTest();
    $token = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'hetzner', 'token' => 'abc', 'name' => 'My Token']);
    $otherTeam = Team::factory()->create();
    $foreignKey = PrivateKey::create(['name' => 'Foreign Key', 'private_key' => HETZNER_CONTROLLER_TEST_KEY, 'team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.new.hetzner.store'), [
            'token_id' => $token->id,
            'private_key_id' => $foreignKey->id,
            'location' => 'fsn1',
            'server_type' => 'cx11',
            'image' => 1,
            'name' => 'my-new-server-4',
            'enable_ipv4' => true,
            'enable_ipv6' => true,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Invalid private key selected.');
});

it('surfaces a rate limit error when hetzner throttles server creation', function () {
    Http::fake([
        'https://api.hetzner.cloud/v1/ssh_keys' => Http::response(['ssh_key' => ['id' => 10, 'name' => 'Deploy Key']], 200),
        'https://api.hetzner.cloud/v1/ssh_keys*' => Http::response([
            'ssh_keys' => [],
            'meta' => ['pagination' => ['next_page' => null]],
        ], 200),
        'https://api.hetzner.cloud/v1/servers' => Http::response([], 429, ['Retry-After' => 5]),
    ]);

    [$user, $team] = actingTeamForHetznerTest();
    $token = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'hetzner', 'token' => 'abc', 'name' => 'My Token']);
    $key = PrivateKey::create(['name' => 'Deploy Key', 'private_key' => HETZNER_CONTROLLER_TEST_KEY, 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.new.hetzner.store'), [
            'token_id' => $token->id,
            'private_key_id' => $key->id,
            'location' => 'fsn1',
            'server_type' => 'cx11',
            'image' => 1,
            'name' => 'my-new-server-5',
            'enable_ipv4' => true,
            'enable_ipv6' => true,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Rate limit exceeded. Please try again later.');
    expect(Server::where('name', 'my-new-server-5')->exists())->toBeFalse();
});
