<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the destination show Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::factory()->create(['server_id' => $server->id, 'name' => 'Primary Network']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('destination.show', ['destination_uuid' => $destination->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Destination/Show')
        ->where('destination.name', 'Primary Network')
        ->where('destination.isStandaloneDocker', true)
        ->where('canUpdate', true)
    );
});

it('updates the destination name', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::factory()->create(['server_id' => $server->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('destination.update', ['destination_uuid' => $destination->uuid]), [
            'name' => 'Renamed Network',
        ]);

    $response->assertRedirect();
    expect($destination->fresh()->name)->toBe('Renamed Network');
});

it('deletes an unattached destination', function () {
    // destroy() shells out to disconnect/remove the Docker network via SSH for a
    // StandaloneDocker destination; fake the process so this test doesn't attempt a real
    // SSH connection to the factory-generated (non-existent) server IP.
    Process::fake();

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'a-removable-network']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('destination.destroy', ['destination_uuid' => $destination->uuid]));

    $response->assertRedirect(route('destination.index'));
    expect(StandaloneDocker::find($destination->id))->toBeNull();
});

it('renders the destination resources Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::factory()->create(['server_id' => $server->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('destination.resources', ['destination_uuid' => $destination->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Destination/Resources')
        ->has('resources', 0)
    );
});
