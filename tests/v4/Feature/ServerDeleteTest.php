<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the server delete Inertia page', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.delete', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/Delete')
        ->has('serverNavbar')
        ->has('sidebar')
        ->where('hasResources', false)
    );
});

it('rejects deletion with an incorrect password', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('server.delete.destroy', ['server_uuid' => $server->uuid]), [
            'password' => 'wrong-password',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect(Server::find($server->id))->not->toBeNull();
});

it('blocks deletion when the server has defined resources', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->first();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('server.delete.destroy', ['server_uuid' => $server->uuid]), [
            'password' => 'password',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect(Server::find($server->id))->not->toBeNull();
});

it('deletes a server with no resources', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('server.delete.destroy', ['server_uuid' => $server->uuid]), [
            'password' => 'password',
        ]);

    $response->assertRedirect(route('server.index'));
    expect(Server::withTrashed()->find($server->id))->toBeNull();
});
