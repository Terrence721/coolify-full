<?php

declare(strict_types=1);

use App\Events\ServerPackageUpdated;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the server security patches Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.security.patches', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/Security/Patches')
        ->has('serverNavbar')
        ->has('sidebar')
        ->where('isDev', false)
    );
});

it('reports a clean error when checking updates on a non-functional server', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_reachable' => false, 'is_usable' => false]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson(route('server.security.patches.check-updates', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertJson(['error' => 'Server is not reachable or not ready.']);
});

it('reports a clean error when updating all packages on a non-functional server', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_reachable' => false, 'is_usable' => false]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.security.patches.update-all', ['server_uuid' => $server->uuid]), [
            'packageManager' => 'apt',
            'osId' => 'ubuntu',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Server is not reachable or not ready.');
});

it('rejects sending a test email outside of dev mode', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.security.patches.send-test-email', ['server_uuid' => $server->uuid]));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Test email functionality is only available in development mode.');
});

it('dispatches the package-updated broadcast event when notified', function () {
    Event::fake([ServerPackageUpdated::class]);
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.security.patches.notify-updated', ['server_uuid' => $server->uuid]));

    $response->assertRedirect();
    Event::assertDispatched(ServerPackageUpdated::class, fn ($event) => $event->teamId === $team->id);
});

it('returns 404 for a server owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.security.patches', ['server_uuid' => $server->uuid]));

    $response->assertNotFound();
});
