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

it('renders the dynamic configurations page without touching SSH on a non-functional server', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.proxy.dynamic-confs', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/Proxy/DynamicConfigurations')
        ->has('serverNavbar')
        ->has('sidebar')
        ->where('isFunctional', false)
        ->has('contents', 0)
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
        ->get(route('server.proxy.dynamic-confs', ['server_uuid' => $server->uuid]));

    $response->assertNotFound();
});

it('rejects a filename with path traversal characters', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.proxy.dynamic-confs.store', ['server_uuid' => $server->uuid]), [
            'fileName' => '../../etc/passwd',
            'value' => 'malicious: true',
            'newFile' => true,
        ]);

    $response->assertSessionHasErrors(['fileName']);
});

it('rejects a reserved filename', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.proxy.dynamic-confs.store', ['server_uuid' => $server->uuid]), [
            'fileName' => 'coolify.yaml',
            'value' => 'foo: bar',
            'newFile' => true,
        ]);

    $response->assertSessionHasErrors(['fileName']);
});

it('returns 404 on store/destroy for a server owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $otherTeam->id]);

    $this->actingAs($user)->withSession(['currentTeam' => $team])
        ->post(route('server.proxy.dynamic-confs.store', ['server_uuid' => $server->uuid]), [
            'fileName' => 'custom.yaml',
            'value' => 'foo: bar',
            'newFile' => true,
        ])
        ->assertNotFound();

    $this->actingAs($user)->withSession(['currentTeam' => $team])
        ->delete(route('server.proxy.dynamic-confs.destroy', ['server_uuid' => $server->uuid]), [
            'fileName' => 'custom.yaml',
        ])
        ->assertNotFound();
});
