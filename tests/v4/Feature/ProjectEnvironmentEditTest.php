<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

// Project::booted()'s static::created hook auto-creates a "production" Environment for every
// new project, so a fresh project already has one environment to edit.

it('renders the environment edit Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.environment.edit', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/EnvironmentEdit')
        ->where('environment.name', 'production')
        ->where('canUpdate', true)
        ->where('canDelete', true)
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
        ->get(route('project.environment.edit', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]));

    $response->assertNotFound();
});

it('updates the environment name and description', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('project.environment.update', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]), [
            'name' => 'staging',
            'description' => 'Staging environment',
        ]);

    $response->assertRedirect(route('project.environment.edit', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]));
    expect($environment->fresh())
        ->name->toBe('staging')
        ->description->toBe('Staging environment');
});

it('deletes an empty environment', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('project.environment.destroy', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]));

    $response->assertRedirect(route('project.show', ['project_uuid' => $project->uuid]));
    expect(Environment::find($environment->id))->toBeNull();
});

it('refuses to delete an environment with resources, without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    Application::factory()->create(['environment_id' => $environment->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('project.environment.destroy', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]));

    $response->assertRedirect();
    $response->assertSessionHas('error', "Environment {$environment->name} has defined resources, please delete them first.");
    expect(Environment::find($environment->id))->not->toBeNull();
});
