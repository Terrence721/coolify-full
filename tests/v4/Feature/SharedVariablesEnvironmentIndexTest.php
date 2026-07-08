<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the shared variables environment index with nested projects and environments', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    // Project::booted() auto-creates a "production" environment on creation.
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments->first();

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('shared-variables.environment.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('SharedVariables/Environment/Index')
        ->has('projects', 1)
        ->where('projects.0.name', $project->name)
        ->has('projects.0.environments', 1)
        ->where('projects.0.environments.0.name', $environment->name)
        ->where('projects.0.environments.0.href', route('shared-variables.environment.show', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
        ]))
    );
});
