<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the server cloudflare tunnel Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.cloudflare-tunnel', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/CloudflareTunnel')
        ->has('serverNavbar')
        ->has('sidebar')
        ->where('isCloudflareTunnelsEnabled', false)
        ->where('canUpdate', true)
    );
});

it('redirects away for the localhost server', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id, 'ip' => 'host.docker.internal']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.cloudflare-tunnel', ['server_uuid' => $server->uuid]));

    $response->assertRedirect(route('server.show', ['server_uuid' => $server->uuid]));
});

it('enables cloudflare tunnel via manual configuration', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.cloudflare-tunnel.manual-config', ['server_uuid' => $server->uuid]));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Cloudflare Tunnel enabled.');
    expect($server->settings->fresh()->is_cloudflare_tunnel)->toBeTruthy();
});

it('rejects automated configuration with missing fields', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.cloudflare-tunnel.automated-config', ['server_uuid' => $server->uuid]), []);

    $response->assertSessionHasErrors(['cloudflare_token', 'ssh_domain']);
});

it('returns 404 for a server owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.cloudflare-tunnel', ['server_uuid' => $server->uuid]));

    $response->assertNotFound();
});
