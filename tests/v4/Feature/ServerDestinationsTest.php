<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the server destinations Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.destinations', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/Destinations')
        ->has('serverNavbar')
        ->has('sidebar')
        ->has('standaloneDockers', 1)
        ->where('canUpdate', true)
        ->where('canCreate', true)
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
        ->get(route('server.destinations', ['server_uuid' => $server->uuid]));

    $response->assertNotFound();
});

it('creates a new standalone docker destination via the modal endpoint', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.destinations.create', ['server_uuid' => $server->uuid]), [
            'name' => 'my-network',
            'network' => 'my-network',
            'server_id' => $server->id,
        ]);

    $destination = StandaloneDocker::where('network', 'my-network')->first();
    expect($destination)->not->toBeNull();
    $response->assertRedirect(route('destination.show', ['destination_uuid' => $destination->uuid]));
});

it('rejects creating a destination with a network already added to the server', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.destinations.create', ['server_uuid' => $server->uuid]), [
            'name' => 'duplicate',
            'network' => 'coolify',
            'server_id' => $server->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Network already added to this server.');
    expect(StandaloneDocker::where('server_id', $server->id)->count())->toBe(1);
});

it('rejects the add endpoint for a network already added to the server, without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.destinations.add', ['server_uuid' => $server->uuid]), [
            'name' => 'coolify',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Network already added to this server.');
    expect(StandaloneDocker::where('server_id', $server->id)->count())->toBe(1);
});
