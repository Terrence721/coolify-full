<?php

declare(strict_types=1);

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

it('renders the project index Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Index')
        ->has('projects', 1)
        ->where('projects.0.name', $project->name)
        ->where('canCreate', true)
    );
});

it('only lists projects owned by the current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    Project::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Index')
        ->has('projects', 0)
    );
});

it('creates a new empty project and redirects into its production environment', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.store'), [
            'name' => 'New Project',
            'description' => 'A fresh project',
        ]);

    $project = Project::where('name', 'New Project')->first();
    expect($project)->not->toBeNull();
    $environment = $project->environments()->where('name', 'production')->first();
    $response->assertRedirect(route('project.resource.index', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
    ]));
});
