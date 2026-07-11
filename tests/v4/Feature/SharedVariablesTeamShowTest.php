<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the team shared variables page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $variable = $team->environment_variables()->create([
        'key' => 'API_KEY', 'value' => 'secret', 'type' => 'team', 'team_id' => $team->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('shared-variables.team.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('SharedVariables/Team/Index')
        ->where('scope', 'team')
        ->where('canUpdate', true)
        ->has('variables', 1)
        ->where('variables.0.key', $variable->key)
    );
});

it('creates a team shared variable', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('shared-variables.team.store'), [
            'key' => 'NODE_ENV', 'value' => 'production', 'is_multiline' => false, 'is_literal' => false,
        ]);

    $response->assertRedirect();
    expect($team->environment_variables()->where('key', 'NODE_ENV')->exists())->toBeTrue();
});

it('rejects creating a duplicate team shared variable', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $team->environment_variables()->create(['key' => 'NODE_ENV', 'value' => 'a', 'type' => 'team', 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('shared-variables.team.store'), [
            'key' => 'NODE_ENV', 'value' => 'b', 'is_multiline' => false, 'is_literal' => false,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Variable already exists.');
    expect($team->environment_variables()->where('key', 'NODE_ENV')->count())->toBe(1);
});

it('updates a team shared variable', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $variable = $team->environment_variables()->create(['key' => 'NODE_ENV', 'value' => 'a', 'type' => 'team', 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('shared-variables.team.update', ['variable_id' => $variable->id]), [
            'key' => 'NODE_ENV', 'value' => 'b', 'comment' => 'updated', 'is_multiline' => false,
            'is_literal' => false, 'is_shown_once' => false,
        ]);

    $response->assertRedirect();
    $fresh = $variable->fresh();
    expect($fresh->value)->toBe('b');
    expect($fresh->comment)->toBe('updated');
});

it('locks a team shared variable', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $variable = $team->environment_variables()->create(['key' => 'NODE_ENV', 'value' => 'a', 'type' => 'team', 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('shared-variables.team.lock', ['variable_id' => $variable->id]));

    $response->assertRedirect();
    expect((bool) $variable->fresh()->is_shown_once)->toBeTrue();
});

it('deletes a team shared variable', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $variable = $team->environment_variables()->create(['key' => 'NODE_ENV', 'value' => 'a', 'type' => 'team', 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('shared-variables.team.destroy', ['variable_id' => $variable->id]));

    $response->assertRedirect();
    expect(SharedEnvironmentVariable::find($variable->id))->toBeNull();
});

it('bulk updates team shared variables', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $team->environment_variables()->create(['key' => 'TO_REMOVE', 'value' => 'x', 'type' => 'team', 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('shared-variables.team.bulk-update'), [
            'variables' => "NODE_ENV=production\nDEBUG=false",
        ]);

    $response->assertRedirect();
    expect($team->environment_variables()->where('key', 'TO_REMOVE')->exists())->toBeFalse();
    expect($team->environment_variables()->where('key', 'NODE_ENV')->value('value'))->toBe('production');
});

it('rejects a member without update permission from creating a variable', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'member']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('shared-variables.team.store'), [
            'key' => 'NODE_ENV', 'value' => 'production', 'is_multiline' => false, 'is_literal' => false,
        ]);

    $response->assertForbidden();
});
