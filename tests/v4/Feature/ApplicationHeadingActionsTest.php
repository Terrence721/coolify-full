<?php

declare(strict_types=1);

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function headingActionsActingAs(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);

    return $user;
}

function headingActionsMakeApplication(Team $team, array $attrs = []): Application
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = \App\Models\Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();

    return Application::factory()->create([
        'name' => 'my-app',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        ...$attrs,
    ]);
}

function headingActionsParams(Application $application): array
{
    return [
        'project_uuid' => $application->environment->project->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
    ];
}

it('deploys an application and redirects to the new deployment', function () {
    Queue::fake();
    $team = Team::factory()->create();
    headingActionsActingAs($team);
    $application = headingActionsMakeApplication($team);

    $response = test()->post(route('project.application.deployment.deploy', headingActionsParams($application)));

    $response->assertRedirect();
    $deployment = ApplicationDeploymentQueue::where('application_id', $application->id)->firstOrFail();
    expect($deployment->force_rebuild)->toBeFalsy();
    Queue::assertPushed(ApplicationDeploymentJob::class);
});

it('force rebuilds without cache when force_rebuild is set', function () {
    Queue::fake();
    $team = Team::factory()->create();
    headingActionsActingAs($team);
    $application = headingActionsMakeApplication($team);

    $response = test()->post(route('project.application.deployment.deploy', headingActionsParams($application)), [
        'force_rebuild' => true,
    ]);

    $response->assertRedirect();
    $deployment = ApplicationDeploymentQueue::where('application_id', $application->id)->firstOrFail();
    expect($deployment->force_rebuild)->toBeTruthy();
});

it('refuses to deploy a compose application with no compose file loaded, without queuing a job', function () {
    Queue::fake();
    $team = Team::factory()->create();
    headingActionsActingAs($team);
    $application = headingActionsMakeApplication($team, [
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => null,
    ]);

    $response = test()->post(route('project.application.deployment.deploy', headingActionsParams($application)));

    $response->assertSessionHas('error', 'Please load a Compose file first.');
    Queue::assertNothingPushed();
});

it('refuses to deploy to a swarm cluster with no docker image name set', function () {
    Queue::fake();
    $team = Team::factory()->create();
    headingActionsActingAs($team);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_swarm_manager' => true]);
    $swarmDestination = SwarmDocker::create(['name' => 'swarm', 'server_id' => $server->id, 'network' => 'swarm_network']);
    $project = \App\Models\Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $application = Application::factory()->create([
        'name' => 'my-swarm-app',
        'environment_id' => $environment->id,
        'destination_id' => $swarmDestination->id,
        'destination_type' => SwarmDocker::class,
        'docker_registry_image_name' => null,
    ]);

    $response = test()->post(route('project.application.deployment.deploy', headingActionsParams($application)));

    $response->assertSessionHas('error', 'To deploy to a Swarm cluster you must set a Docker image name first.');
    Queue::assertNothingPushed();
});

it('restarts an application without rebuilding', function () {
    Queue::fake();
    $team = Team::factory()->create();
    headingActionsActingAs($team);
    $application = headingActionsMakeApplication($team);

    $response = test()->post(route('project.application.deployment.restart', headingActionsParams($application)));

    $response->assertRedirect();
    $deployment = ApplicationDeploymentQueue::where('application_id', $application->id)->firstOrFail();
    expect($deployment->restart_only)->toBeTruthy();
    Queue::assertPushed(ApplicationDeploymentJob::class);
});

it('gracefully stops an application', function () {
    Queue::fake();
    $team = Team::factory()->create();
    headingActionsActingAs($team);
    $application = headingActionsMakeApplication($team);

    $response = test()->post(route('project.application.deployment.stop', headingActionsParams($application)));

    $response->assertRedirect();
    $response->assertSessionHas('info', 'Gracefully stopping application. It could take a while depending on the application.');
});

it('checks status against a functional server', function () {
    Queue::fake();
    $team = Team::factory()->create();
    headingActionsActingAs($team);
    $application = headingActionsMakeApplication($team);
    $application->destination->server->settings->update(['is_reachable' => true, 'is_usable' => true]);

    $response = test()->post(route('project.application.deployment.check-status', headingActionsParams($application)));

    $response->assertRedirect();
    $response->assertSessionMissing('error');
});

it('reports an error checking status against a non-functional server, without touching SSH', function () {
    // A fresh server's settings default to not-reachable/not-usable, so isFunctional()
    // is false without any extra setup here.
    Queue::fake();
    $team = Team::factory()->create();
    headingActionsActingAs($team);
    $application = headingActionsMakeApplication($team);

    $response = test()->post(route('project.application.deployment.check-status', headingActionsParams($application)));

    $response->assertSessionHas('error', 'Server is not functional.');
});

it('redirects deployment actions to the dashboard for a nonexistent application', function () {
    $team = Team::factory()->create();
    headingActionsActingAs($team);

    $params = [
        'project_uuid' => 'does-not-exist',
        'environment_uuid' => 'does-not-exist',
        'application_uuid' => 'does-not-exist',
    ];

    test()->post(route('project.application.deployment.deploy', $params))->assertRedirect(route('dashboard'));
    test()->post(route('project.application.deployment.restart', $params))->assertRedirect(route('dashboard'));
    test()->post(route('project.application.deployment.stop', $params))->assertRedirect(route('dashboard'));
    test()->post(route('project.application.deployment.check-status', $params))->assertRedirect(route('dashboard'));
});
