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

it('renders the project shared variables page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $variable = $project->environment_variables()->create([
        'key' => 'API_KEY', 'value' => 'secret', 'type' => 'project', 'team_id' => $team->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('shared-variables.project.show', ['project_uuid' => $project->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('SharedVariables/Project/Show')
        ->where('scope', 'project')
        ->where('label', $project->name)
        ->has('variables', 1)
        ->where('variables.0.key', $variable->key)
    );
});

it('returns 404 for a project owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('shared-variables.project.show', ['project_uuid' => $project->uuid]));

    $response->assertNotFound();
});

it('creates a project shared variable', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('shared-variables.project.store', ['project_uuid' => $project->uuid]), [
            'key' => 'NODE_ENV', 'value' => 'production', 'is_multiline' => false, 'is_literal' => false,
        ]);

    $response->assertRedirect();
    expect($project->environment_variables()->where('key', 'NODE_ENV')->exists())->toBeTrue();
});

it('updates a project shared variable', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $variable = $project->environment_variables()->create(['key' => 'NODE_ENV', 'value' => 'a', 'type' => 'project', 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('shared-variables.project.update', ['project_uuid' => $project->uuid, 'variable_id' => $variable->id]), [
            'key' => 'NODE_ENV', 'value' => 'b', 'comment' => 'updated', 'is_multiline' => false,
            'is_literal' => false, 'is_shown_once' => false,
        ]);

    $response->assertRedirect();
    $fresh = $variable->fresh();
    expect($fresh->value)->toBe('b');
    expect($fresh->comment)->toBe('updated');
});

it('locks a project shared variable', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $variable = $project->environment_variables()->create(['key' => 'NODE_ENV', 'value' => 'a', 'type' => 'project', 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('shared-variables.project.lock', ['project_uuid' => $project->uuid, 'variable_id' => $variable->id]));

    $response->assertRedirect();
    expect((bool) $variable->fresh()->is_shown_once)->toBeTrue();
});

it('deletes a project shared variable', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $variable = $project->environment_variables()->create(['key' => 'NODE_ENV', 'value' => 'a', 'type' => 'project', 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('shared-variables.project.destroy', ['project_uuid' => $project->uuid, 'variable_id' => $variable->id]));

    $response->assertRedirect();
    expect(SharedEnvironmentVariable::find($variable->id))->toBeNull();
});

it('bulk updates project shared variables', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $project->environment_variables()->create(['key' => 'TO_REMOVE', 'value' => 'x', 'type' => 'project', 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('shared-variables.project.bulk-update', ['project_uuid' => $project->uuid]), [
            'variables' => "NODE_ENV=production\nDEBUG=false",
        ]);

    $response->assertRedirect();
    expect($project->environment_variables()->where('key', 'TO_REMOVE')->exists())->toBeFalse();
    expect($project->environment_variables()->where('key', 'NODE_ENV')->value('value'))->toBe('production');
});
