<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

// Application::factory()'s destination_id => 1 doesn't correspond to a real row, but the
// controller (like the original Livewire component) dereferences $application->destination
// ->server, so a real StandaloneDocker + Server is required - same gotcha as the Destination
// pages' tests. Server::factory() auto-creates a default StandaloneDocker (see
// DestinationShowTest), which is reused here as the application's destination.
function createApplicationWithFullChain(Team $team, array $attributes = []): Application
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->first();

    return Application::factory()->create([
        ...$attributes,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
}

it('renders the application deployment index Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $application = createApplicationWithFullChain($team, ['name' => 'My App']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.application.deployment.index', [
            'project_uuid' => $application->environment->project->uuid,
            'environment_uuid' => $application->environment->uuid,
            'application_uuid' => $application->uuid,
        ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Application/Deployment/Index')
        ->where('application.name', 'My App')
        ->has('deployments', 0)
    );
});

it('lists an existing deployment for the application', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $application = createApplicationWithFullChain($team);
    ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => (string) Str::uuid(),
        'status' => 'finished',
        'pull_request_id' => 0,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.application.deployment.index', [
            'project_uuid' => $application->environment->project->uuid,
            'environment_uuid' => $application->environment->uuid,
            'application_uuid' => $application->uuid,
        ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Application/Deployment/Index')
        ->has('deployments', 1)
        ->where('deployments.0.status', 'finished')
    );
});

it('redirects to dashboard for a nonexistent application', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.application.deployment.index', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => 'does-not-exist',
        ]));

    $response->assertRedirect(route('dashboard'));
});
