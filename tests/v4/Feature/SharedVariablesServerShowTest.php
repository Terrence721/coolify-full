<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function createTeamWithServer(User $user): array
{
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    return [$team, $server];
}

it('renders the server shared variables page and excludes predefined variables', function () {
    $user = User::factory()->create();
    [$team, $server] = createTeamWithServer($user);
    // Server::factory()'s created hook already seeds COOLIFY_SERVER_UUID/COOLIFY_SERVER_NAME.
    $variable = $server->environment_variables()->create([
        'key' => 'API_KEY', 'value' => 'secret', 'type' => 'server', 'team_id' => $team->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('shared-variables.server.show', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('SharedVariables/Server/Show')
        ->where('scope', 'server')
        ->where('label', $server->name)
        ->has('variables', 1)
        ->where('variables.0.key', $variable->key)
    );
});

it('returns 404 for a server owned by another team', function () {
    $user = User::factory()->create();
    [$team] = createTeamWithServer($user);
    $otherUser = User::factory()->create();
    [, $otherServer] = createTeamWithServer($otherUser);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('shared-variables.server.show', ['server_uuid' => $otherServer->uuid]));

    $response->assertNotFound();
});

it('creates a server shared variable', function () {
    $user = User::factory()->create();
    [$team, $server] = createTeamWithServer($user);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('shared-variables.server.store', ['server_uuid' => $server->uuid]), [
            'key' => 'NODE_ENV', 'value' => 'production', 'is_multiline' => false, 'is_literal' => false,
        ]);

    $response->assertRedirect();
    expect($server->environment_variables()->where('key', 'NODE_ENV')->exists())->toBeTrue();
});

it('rejects creating a predefined server variable', function () {
    $user = User::factory()->create();
    [$team, $server] = createTeamWithServer($user);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('shared-variables.server.store', ['server_uuid' => $server->uuid]), [
            'key' => 'COOLIFY_SERVER_UUID', 'value' => 'x', 'is_multiline' => false, 'is_literal' => false,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Cannot create predefined variable.');
    expect($server->environment_variables()->where('key', 'COOLIFY_SERVER_UUID')->value('value'))->toBe($server->uuid);
});

it('updates a server shared variable', function () {
    $user = User::factory()->create();
    [$team, $server] = createTeamWithServer($user);
    $variable = $server->environment_variables()->create(['key' => 'NODE_ENV', 'value' => 'a', 'type' => 'server', 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('shared-variables.server.update', ['server_uuid' => $server->uuid, 'variable_id' => $variable->id]), [
            'key' => 'NODE_ENV', 'value' => 'b', 'comment' => 'updated', 'is_multiline' => false,
            'is_literal' => false, 'is_shown_once' => false,
        ]);

    $response->assertRedirect();
    $fresh = $variable->fresh();
    expect($fresh->value)->toBe('b');
    expect($fresh->comment)->toBe('updated');
});

it('locks a server shared variable', function () {
    $user = User::factory()->create();
    [$team, $server] = createTeamWithServer($user);
    $variable = $server->environment_variables()->create(['key' => 'NODE_ENV', 'value' => 'a', 'type' => 'server', 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('shared-variables.server.lock', ['server_uuid' => $server->uuid, 'variable_id' => $variable->id]));

    $response->assertRedirect();
    expect((bool) $variable->fresh()->is_shown_once)->toBeTrue();
});

it('deletes a server shared variable', function () {
    $user = User::factory()->create();
    [$team, $server] = createTeamWithServer($user);
    $variable = $server->environment_variables()->create(['key' => 'NODE_ENV', 'value' => 'a', 'type' => 'server', 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('shared-variables.server.destroy', ['server_uuid' => $server->uuid, 'variable_id' => $variable->id]));

    $response->assertRedirect();
    expect(SharedEnvironmentVariable::find($variable->id))->toBeNull();
});

it('bulk updates server shared variables without touching predefined variables', function () {
    $user = User::factory()->create();
    [$team, $server] = createTeamWithServer($user);
    $server->environment_variables()->create(['key' => 'TO_REMOVE', 'value' => 'x', 'type' => 'server', 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('shared-variables.server.bulk-update', ['server_uuid' => $server->uuid]), [
            'variables' => "NODE_ENV=production\nDEBUG=false",
        ]);

    $response->assertRedirect();
    expect($server->environment_variables()->where('key', 'TO_REMOVE')->exists())->toBeFalse();
    expect($server->environment_variables()->where('key', 'COOLIFY_SERVER_UUID')->exists())->toBeTrue();
    expect($server->environment_variables()->where('key', 'NODE_ENV')->value('value'))->toBe('production');
});
