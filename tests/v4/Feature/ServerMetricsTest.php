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

it('renders the server metrics Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_metrics_enabled' => false]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.metrics', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/Metrics')
        ->has('serverNavbar')
        ->has('sidebar')
        ->where('isMetricsEnabled', false)
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
        ->get(route('server.metrics', ['server_uuid' => $server->uuid]));

    $response->assertNotFound();
});

it('returns 404 on toggle for a server owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.metrics.toggle', ['server_uuid' => $server->uuid]));

    $response->assertNotFound();
});

it('returns null metrics without touching SSH when metrics are disabled', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_metrics_enabled' => false]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson(route('server.metrics.data', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertJson(['cpu' => null, 'memory' => null]);
});

it('returns 404 on the data endpoint for a server owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson(route('server.metrics.data', ['server_uuid' => $server->uuid]));

    $response->assertNotFound();
});
