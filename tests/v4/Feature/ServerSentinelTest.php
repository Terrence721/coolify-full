<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the server sentinel Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.sentinel', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/Sentinel')
        ->has('serverNavbar')
        ->has('sidebar')
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
        ->get(route('server.sentinel', ['server_uuid' => $server->uuid]));

    $response->assertNotFound();
});

it('saves sentinel settings, dispatching (not running) the sentinel restart job', function () {
    Queue::fake();

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.sentinel.submit', ['server_uuid' => $server->uuid]), [
            'sentinelToken' => 'a-valid-token123',
            'sentinelCustomUrl' => 'https://coolify.example.com',
            'sentinelMetricsRefreshRateSeconds' => 5,
            'sentinelMetricsHistoryDays' => 7,
            'sentinelPushIntervalSeconds' => 10,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Sentinel settings updated.');
    $server->settings->refresh();
    expect($server->settings->sentinel_token)->toBe('a-valid-token123');
    expect($server->settings->sentinel_custom_url)->toBe('https://coolify.example.com');
});

it('rejects an invalid sentinel token on save', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.sentinel.submit', ['server_uuid' => $server->uuid]), [
            'sentinelToken' => 'has invalid spaces!',
            'sentinelMetricsRefreshRateSeconds' => 5,
            'sentinelMetricsHistoryDays' => 7,
            'sentinelPushIntervalSeconds' => 10,
        ]);

    $response->assertSessionHasErrors(['sentinelToken']);
});

it('refuses to enable sentinel on a build server without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_build_server' => true, 'is_sentinel_enabled' => false]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.sentinel.toggle', ['server_uuid' => $server->uuid]));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Sentinel cannot be enabled on build servers.');
    expect($server->settings->fresh()->is_sentinel_enabled)->toBeFalsy();
});

it('regenerates the sentinel token, dispatching (not running) the sentinel restart job', function () {
    Queue::fake();

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $originalToken = $server->settings->sentinel_token;

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.sentinel.regenerate-token', ['server_uuid' => $server->uuid]));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Token regenerated. Restarting Sentinel.');
    expect($server->settings->fresh()->sentinel_token)->not->toBe($originalToken);
});

it('returns 404 on every sentinel action for a server owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $otherTeam->id]);

    $this->actingAs($user)->withSession(['currentTeam' => $team])
        ->post(route('server.sentinel.submit', ['server_uuid' => $server->uuid]), [
            'sentinelToken' => 'a-valid-token123',
            'sentinelMetricsRefreshRateSeconds' => 5,
            'sentinelMetricsHistoryDays' => 7,
            'sentinelPushIntervalSeconds' => 10,
        ])
        ->assertNotFound();

    $this->actingAs($user)->withSession(['currentTeam' => $team])
        ->post(route('server.sentinel.toggle', ['server_uuid' => $server->uuid]))
        ->assertNotFound();

    $this->actingAs($user)->withSession(['currentTeam' => $team])
        ->post(route('server.sentinel.regenerate-token', ['server_uuid' => $server->uuid]))
        ->assertNotFound();
});
