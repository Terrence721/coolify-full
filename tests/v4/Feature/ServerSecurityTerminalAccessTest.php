<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the server terminal access Inertia page', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.security.terminal-access', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/Security/TerminalAccess')
        ->has('serverNavbar')
        ->has('sidebar')
        ->where('isAdmin', true)
    );
});

it('toggles terminal access with the correct password', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $before = $server->settings->is_terminal_enabled;

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('server.security.terminal-access.toggle', ['server_uuid' => $server->uuid]), [
            'password' => 'password',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect($server->settings->fresh()->is_terminal_enabled)->not->toBe($before);
});

it('rejects toggling terminal access with an incorrect password', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $before = $server->settings->is_terminal_enabled;

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('server.security.terminal-access.toggle', ['server_uuid' => $server->uuid]), [
            'password' => 'wrong-password',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect($server->settings->fresh()->is_terminal_enabled)->toBe($before);
});
