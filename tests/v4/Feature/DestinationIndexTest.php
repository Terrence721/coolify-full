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

// Server::boot()'s static::created hook auto-creates a default StandaloneDocker (name/network
// both "coolify") for every server, so a fresh usable server already has one destination.
function makeUsableServer(int $teamId): Server
{
    $server = Server::factory()->create(['team_id' => $teamId]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);

    return $server;
}

it('renders the destination index Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    makeUsableServer($team->id);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('destination.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Destination/Index')
        ->has('destinations', 1)
        ->where('destinations.0.name', 'coolify')
        ->where('hasServers', true)
        ->where('canCreate', true)
    );
});

it('excludes servers not usable by the current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    // Not marked reachable/usable - excluded by Server::isUsable().
    Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('destination.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Destination/Index')
        ->has('destinations', 0)
        ->where('hasServers', false)
    );
});

it('creates a new standalone docker destination via the modal endpoint', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = makeUsableServer($team->id);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('destination.store'), [
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
    $server = makeUsableServer($team->id);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('destination.store'), [
            'name' => 'duplicate',
            'network' => 'coolify',
            'server_id' => $server->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Network already added to this server.');
    expect(StandaloneDocker::where('server_id', $server->id)->count())->toBe(1);
});

it('rejects creating a destination for a server owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $otherServer = makeUsableServer($otherTeam->id);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('destination.store'), [
            'name' => 'sneaky',
            'network' => 'sneaky',
            'server_id' => $otherServer->id,
        ]);

    $response->assertNotFound();
});
