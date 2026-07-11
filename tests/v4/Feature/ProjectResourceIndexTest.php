<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

// Project::booted()'s static::created hook auto-creates a "production" Environment for every
// new project, so a fresh project already has one environment to list resources for.

it('renders the empty-state resource index page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Resource/Index')
        ->where('environment.isEmpty', true)
        ->where('canCreate', true)
        ->has('applications', 0)
        ->has('databases', 0)
        ->has('services', 0)
    );
});

it('lists applications and services with their configuration links', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $application = Application::factory()->create(['environment_id' => $environment->id, 'name' => 'my-app']);
    $service = Service::factory()->create(['environment_id' => $environment->id, 'name' => 'my-service']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Resource/Index')
        ->where('environment.isEmpty', false)
        ->has('applications', 1)
        ->where('applications.0.name', 'my-app')
        ->where('applications.0.hrefLink', route('project.application.configuration', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => $application->uuid,
        ]))
        ->has('services', 1)
        ->where('services.0.name', 'my-service')
    );
});

it('lists sibling environments and their resources for the breadcrumb dropdown', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $staging = $project->environments()->create(['name' => 'staging', 'uuid' => (string) new Cuid2]);
    Application::factory()->create(['environment_id' => $staging->id, 'name' => 'staging-app']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Resource/Index')
        ->has('allEnvironments', 2)
    );
});

it('404s for an environment in a project owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $otherTeam->id]);
    $environment = $project->environments()->first();

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]));

    $response->assertNotFound();
});
