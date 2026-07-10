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

// Project::booted()'s static::created hook (app/Models/Project.php) auto-creates a "production"
// Environment for every new project, so a fresh project already has one environment.

it('renders the project show Inertia page with its environments', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.show', ['project_uuid' => $project->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Show')
        ->where('project.name', $project->name)
        ->has('environments', 1)
        ->where('environments.0.name', 'production')
        ->where('canUpdate', true)
        ->where('canDelete', true)
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
        ->get(route('project.show', ['project_uuid' => $project->uuid]));

    $response->assertNotFound();
});

it('creates a new environment from the show page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.create-environment', ['project_uuid' => $project->uuid]), [
            'name' => 'staging',
        ]);

    $environment = Environment::where('project_id', $project->id)->where('name', 'staging')->first();
    expect($environment)->not->toBeNull();
    $response->assertRedirect(route('project.resource.index', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
    ]));
});

it('renders the project edit Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id, 'description' => 'A test project']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.edit', ['project_uuid' => $project->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Edit')
        ->where('project.name', $project->name)
        ->where('project.description', 'A test project')
        ->where('canDelete', true)
    );
});

it('updates the project name and description', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('project.update', ['project_uuid' => $project->uuid]), [
            'name' => 'Renamed Project',
            'description' => 'Updated description',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Project updated.');
    expect($project->fresh())
        ->name->toBe('Renamed Project')
        ->description->toBe('Updated description');
});

it('deletes an empty project', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('project.destroy', ['project_uuid' => $project->uuid]));

    $response->assertRedirect(route('project.index'));
    expect(Project::find($project->id))->toBeNull();
});

it('refuses to delete a project with resources, without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    Application::factory()->create(['environment_id' => $environment->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('project.destroy', ['project_uuid' => $project->uuid]));

    $response->assertRedirect();
    $response->assertSessionHas('error', "Project {$project->name} has resources defined, please delete them first.");
    expect(Project::find($project->id))->not->toBeNull();
});
