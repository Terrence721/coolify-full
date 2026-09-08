<?php

declare(strict_types=1);

use App\Actions\Service\RestartService;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceApplication;
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

// Service has no status column of its own — getStatusAttribute() aggregates it live from
// child ServiceApplication/ServiceDatabase container statuses, so "running"/"exited" must
// be simulated via a child row's status, not set directly on the service.
function apiActionsMakeService(Team $team, ?string $childStatus = null): Service
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();

    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    if ($childStatus !== null) {
        ServiceApplication::create([
            'name' => 'child',
            'service_id' => $service->id,
            'status' => $childStatus,
        ]);
    }

    return $service;
}

it('queues a start for a stopped service', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiActionsMakeService($team, 'exited:unhealthy');
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/services/{$service->uuid}/start");

    $response->assertOk();
    Queue::assertPushed(JobDecorator::class, fn ($job) => $job->decorates(StartService::class));
});

it('rejects starting an already-running service', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiActionsMakeService($team, 'running:healthy');
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/services/{$service->uuid}/start");

    $response->assertStatus(400);
});

it('returns 404 starting another team\'s service', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiActionsMakeService($otherTeam, 'exited:unhealthy');
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/services/{$service->uuid}/start");

    $response->assertNotFound();
});

// action_stop() authorizes against the 'stop' policy ability, while action_deploy()/
// action_restart() use 'deploy' — a real, currently-dormant inconsistency (ServicePolicy
// is hardcoded to allow everything today, so it has no observable HTTP effect yet, but a
// future policy re-enablement must not silently change which ability gates stop). Route-level
// Sanctum ability is 'deploy' for all three actions regardless.
it('queues a stop for a running service', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiActionsMakeService($team, 'running:healthy');
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/services/{$service->uuid}/stop");

    $response->assertOk();
    Queue::assertPushed(JobDecorator::class, fn ($job) => $job->decorates(StopService::class));
});

it('stops the service named in the URL path, not a uuid supplied in the query string', function () {
    // Regression test: Laravel's Request::__get() is Arr::get($this->all(), $key, fn () =>
    // $this->route($key)) - request input wins over the route parameter. action_stop()
    // (like action_deploy()/action_restart()) fetched $request->route('uuid') into a local
    // $uuid variable and null-checked it, but then discarded it in favor of $request->uuid
    // for the actual database lookup - so a caller could stop a different service than the
    // one named in the URL just by also supplying a uuid query param. Proven black-box: the
    // URL's service is already stopped (would 400 "already stopped" if acted on), the
    // query-string service is running (200s and queues a stop) - a 200 here proves the
    // wrong service was acted on.
    Queue::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $urlService = apiActionsMakeService($team, 'exited:unhealthy');
    $queryStringService = apiActionsMakeService($team, 'running:healthy');
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))
        ->getJson("/api/v1/services/{$urlService->uuid}/stop?uuid={$queryStringService->uuid}");

    $response->assertStatus(400);
});

it('rejects stopping an already-stopped service', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiActionsMakeService($team, 'exited:unhealthy');
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/services/{$service->uuid}/stop");

    $response->assertStatus(400);
});

it('returns 404 stopping another team\'s service', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiActionsMakeService($otherTeam, 'running:healthy');
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/services/{$service->uuid}/stop");

    $response->assertNotFound();
});

it('queues a restart', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiActionsMakeService($team, 'running:healthy');
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/services/{$service->uuid}/restart");

    $response->assertOk();
    Queue::assertPushed(JobDecorator::class, fn ($job) => $job->decorates(RestartService::class));
});

it('returns 404 restarting another team\'s service', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiActionsMakeService($otherTeam, 'running:healthy');
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/services/{$service->uuid}/restart");

    $response->assertNotFound();
});

it('rejects a start with a token missing the deploy ability', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiActionsMakeService($team, 'exited:unhealthy');
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/services/{$service->uuid}/start");

    $response->assertForbidden();
});
