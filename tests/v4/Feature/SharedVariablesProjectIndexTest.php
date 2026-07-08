<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the shared variables project index with the team\'s projects', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('shared-variables.project.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('SharedVariables/Project/Index')
        ->has('projects', 1)
        ->where('projects.0.name', $project->name)
        ->where('projects.0.href', route('shared-variables.project.show', ['project_uuid' => $project->uuid]))
    );
});
