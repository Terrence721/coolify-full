<?php

declare(strict_types=1);

use App\Jobs\ApplicationDeploymentJob;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

// Same fixture shape as ApplicationDeploymentIndexTest's createApplicationWithFullChain(),
// renamed to avoid Pest's global-function-name collision across test files (all Feature
// test files share one PHP process - see the Phase 43 note on makeSettingsIndexAdmin()).
function createDeploymentShowApplication(Team $team, array $attributes = []): Application
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

function deploymentShowParams(Application $application, ApplicationDeploymentQueue $deployment): array
{
    return [
        'project_uuid' => $application->environment->project->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
        'deployment_uuid' => $deployment->deployment_uuid,
    ];
}

it('renders the deployment show page with log lines', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $application = createDeploymentShowApplication($team, ['name' => 'My App']);
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => (string) Str::uuid(),
        'status' => 'in_progress',
        'pull_request_id' => 0,
        'logs' => json_encode([
            ['command' => null, 'output' => 'Starting deployment', 'type' => 'stdout', 'order' => 1, 'timestamp' => now()->toIso8601String(), 'hidden' => false],
        ]),
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.application.deployment.show', deploymentShowParams($application, $deployment)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Application/Deployment/Show')
        ->where('deployment.deployment_uuid', $deployment->deployment_uuid)
        ->where('deployment.status', 'in_progress')
        ->where('isKeepAliveOn', true)
        ->has('logLines', 1)
    );
});

it('marks a finished deployment as not needing to keep polling', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $application = createDeploymentShowApplication($team);
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => (string) Str::uuid(),
        'status' => 'finished',
        'pull_request_id' => 0,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.application.deployment.show', deploymentShowParams($application, $deployment)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->where('isKeepAliveOn', false));
});

it('redirects to the deployment index for a nonexistent deployment uuid', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $application = createDeploymentShowApplication($team);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.application.deployment.show', [
            'project_uuid' => $application->environment->project->uuid,
            'environment_uuid' => $application->environment->uuid,
            'application_uuid' => $application->uuid,
            'deployment_uuid' => 'does-not-exist',
        ]));

    $response->assertRedirect(route('project.application.deployment.index', [
        'project_uuid' => $application->environment->project->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
    ]));
});

it('toggles debug mode for the application', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $application = createDeploymentShowApplication($team);
    expect($application->settings->is_debug_enabled)->toBeFalsy();

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.application.deployment.toggle-debug', [
            'project_uuid' => $application->environment->project->uuid,
            'environment_uuid' => $application->environment->uuid,
            'application_uuid' => $application->uuid,
        ]));

    $response->assertRedirect();
    expect($application->settings->fresh()->is_debug_enabled)->toBeTruthy();
});

it('force starts a queued deployment', function () {
    Queue::fake();

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $application = createDeploymentShowApplication($team);
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => (string) Str::uuid(),
        'status' => 'queued',
        'pull_request_id' => 0,
        // ApplicationDeploymentJob's constructor dereferences Server::find($server_id)
        // unconditionally, so a bare deployment fixture crashes it with a null server.
        'server_id' => $application->destination->server_id,
        'destination_id' => $application->destination_id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.application.deployment.force-start', deploymentShowParams($application, $deployment)));

    $response->assertRedirect();
    expect($deployment->fresh()->status)->toBe('in_progress');
    Queue::assertPushed(ApplicationDeploymentJob::class);
});

it('rejects cancelling a deployment that does not exist, without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $application = createDeploymentShowApplication($team);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.application.deployment.cancel', [
            'project_uuid' => $application->environment->project->uuid,
            'environment_uuid' => $application->environment->uuid,
            'application_uuid' => $application->uuid,
            'deployment_uuid' => 'does-not-exist',
        ]));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Deployment not found.');
});

it('downloads all logs as a plain text file', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $application = createDeploymentShowApplication($team);
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => (string) Str::uuid(),
        'status' => 'finished',
        'pull_request_id' => 0,
        'logs' => json_encode([
            ['command' => null, 'output' => 'Build finished', 'type' => 'stdout', 'order' => 1, 'timestamp' => now()->toIso8601String(), 'hidden' => false],
        ]),
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.application.deployment.download-all-logs', deploymentShowParams($application, $deployment)));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    expect($response->getContent())->toContain('Build finished');
});
