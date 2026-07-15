<?php

declare(strict_types=1);

use App\Actions\Application\StopApplication;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

function apiActionsMakeApplication(Team $team, array $attrs = []): Application
{
    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $rsaKey);
    $privateKey = PrivateKey::create(['name' => 'throwaway-key', 'private_key' => $rsaKey, 'team_id' => $team->id]);

    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        ...$attrs,
    ]);
}

it('queues a deployment on start', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiActionsMakeApplication($team);
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/applications/{$application->uuid}/start");

    $response->assertOk();
    $response->assertJsonStructure(['message', 'deployment_uuid']);
    Queue::assertPushed(ApplicationDeploymentJob::class);
});

it('returns 404 starting another team\'s application', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiActionsMakeApplication($otherTeam);
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/applications/{$application->uuid}/start");

    $response->assertNotFound();
});

it('queues a stop', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiActionsMakeApplication($team);
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/applications/{$application->uuid}/stop");

    $response->assertOk();
    // StopApplication is a lorisleiva Action; ::dispatch() wraps it in a JobDecorator
    // rather than queueing StopApplication itself.
    Queue::assertPushed(JobDecorator::class, fn ($job) => $job->decorates(StopApplication::class));
});

it('returns 404 stopping another team\'s application', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiActionsMakeApplication($otherTeam);
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/applications/{$application->uuid}/stop");

    $response->assertNotFound();
});

it('queues a deployment on restart', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiActionsMakeApplication($team);
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/applications/{$application->uuid}/restart");

    $response->assertOk();
    $response->assertJsonStructure(['message', 'deployment_uuid']);
    Queue::assertPushed(ApplicationDeploymentJob::class);
});

it('returns 404 restarting another team\'s application', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiActionsMakeApplication($otherTeam);
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/applications/{$application->uuid}/restart");

    $response->assertNotFound();
});

it('rejects a start with a token missing the deploy ability', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiActionsMakeApplication($team);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/applications/{$application->uuid}/start");

    $response->assertForbidden();
});
