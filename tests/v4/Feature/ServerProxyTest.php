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

it('renders the server proxy Inertia page with no proxy selected', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.proxy', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/Proxy')
        ->has('serverNavbar')
        ->has('sidebar')
        ->where('selectedProxy', null)
        ->where('canUpdate', true)
    );
});

it('returns 404 for a server owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.proxy', ['server_uuid' => $server->uuid]));

    $response->assertNotFound();
});

it('selects a proxy type without touching SSH on a non-functional server', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.proxy.select', ['server_uuid' => $server->uuid]), [
            'proxy_type' => 'TRAEFIK',
        ]);

    $response->assertRedirect();
    expect($server->fresh()->proxyType())->toBe('TRAEFIK');
});

it('rejects an invalid proxy type', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.proxy.select', ['server_uuid' => $server->uuid]), [
            'proxy_type' => 'NOT_REAL',
        ]);

    $response->assertSessionHasErrors(['proxy_type']);
});

it('resets the proxy selection without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', 'TRAEFIK');
    $server->save();

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.proxy.reset-selection', ['server_uuid' => $server->uuid]));

    $response->assertRedirect();
    expect($server->fresh()->proxyType())->toBeNull();
});

it('instant-saves the generate exact labels setting without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.proxy.instant-save', ['server_uuid' => $server->uuid]), [
            'generateExactLabels' => true,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Settings saved.');
    expect($server->settings->fresh()->generate_exact_labels)->toBeTruthy();
});

it('returns 404 on every proxy action for a server owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $otherTeam->id]);

    $this->actingAs($user)->withSession(['currentTeam' => $team])
        ->post(route('server.proxy.select', ['server_uuid' => $server->uuid]), ['proxy_type' => 'TRAEFIK'])
        ->assertNotFound();

    $this->actingAs($user)->withSession(['currentTeam' => $team])
        ->post(route('server.proxy.reset-selection', ['server_uuid' => $server->uuid]))
        ->assertNotFound();

    $this->actingAs($user)->withSession(['currentTeam' => $team])
        ->post(route('server.proxy.instant-save', ['server_uuid' => $server->uuid]), ['generateExactLabels' => true])
        ->assertNotFound();
});
