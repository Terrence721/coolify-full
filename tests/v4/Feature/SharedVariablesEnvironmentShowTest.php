<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

// Project::booted()'s static::created hook auto-creates a "production" Environment for every
// new project, so a fresh project already has one environment to work with.

it('renders the environment shared variables page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $variable = $environment->environment_variables()->create([
        'key' => 'API_KEY', 'value' => 'secret', 'type' => 'environment', 'team_id' => $team->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('shared-variables.environment.show', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('SharedVariables/Environment/Show')
        ->where('scope', 'environment')
        ->where('label', $environment->name)
        ->has('variables', 1)
        ->where('variables.0.key', $variable->key)
    );
});

it('returns 404 for an environment in a project owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $otherTeam->id]);
    $environment = $project->environments()->first();

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('shared-variables.environment.show', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]));

    $response->assertNotFound();
});

it('creates an environment shared variable', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('shared-variables.environment.store', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]), [
            'key' => 'NODE_ENV', 'value' => 'production', 'is_multiline' => false, 'is_literal' => false,
        ]);

    $response->assertRedirect();
    expect($environment->environment_variables()->where('key', 'NODE_ENV')->exists())->toBeTrue();
});

it('updates an environment shared variable', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $variable = $environment->environment_variables()->create(['key' => 'NODE_ENV', 'value' => 'a', 'type' => 'environment', 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('shared-variables.environment.update', [
            'project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'variable_id' => $variable->id,
        ]), [
            'key' => 'NODE_ENV', 'value' => 'b', 'comment' => 'updated', 'is_multiline' => false,
            'is_literal' => false, 'is_shown_once' => false,
        ]);

    $response->assertRedirect();
    $fresh = $variable->fresh();
    expect($fresh->value)->toBe('b');
    expect($fresh->comment)->toBe('updated');
});

it('locks an environment shared variable', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $variable = $environment->environment_variables()->create(['key' => 'NODE_ENV', 'value' => 'a', 'type' => 'environment', 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('shared-variables.environment.lock', [
            'project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'variable_id' => $variable->id,
        ]));

    $response->assertRedirect();
    expect((bool) $variable->fresh()->is_shown_once)->toBeTrue();
});

it('deletes an environment shared variable', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $variable = $environment->environment_variables()->create(['key' => 'NODE_ENV', 'value' => 'a', 'type' => 'environment', 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('shared-variables.environment.destroy', [
            'project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'variable_id' => $variable->id,
        ]));

    $response->assertRedirect();
    expect(SharedEnvironmentVariable::find($variable->id))->toBeNull();
});

it('bulk updates environment shared variables', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $environment->environment_variables()->create(['key' => 'TO_REMOVE', 'value' => 'x', 'type' => 'environment', 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('shared-variables.environment.bulk-update', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]), [
            'variables' => "NODE_ENV=production\nDEBUG=false",
        ]);

    $response->assertRedirect();
    expect($environment->environment_variables()->where('key', 'TO_REMOVE')->exists())->toBeFalse();
    expect($environment->environment_variables()->where('key', 'NODE_ENV')->value('value'))->toBe('production');
});
