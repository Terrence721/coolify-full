<?php

declare(strict_types=1);

use App\Jobs\DeleteResourceJob;
use App\Models\Project;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

function apiMakeService(Team $team, array $attrs = []): Service
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();

    return Service::factory()->create([
        'environment_id' => $environment->id,
        'destination_type' => StandaloneDocker::class,
        ...$attrs,
    ]);
}

it('lists services owned by the token team', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    apiMakeService($team, ['name' => 'my-service']);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/services');

    $response->assertOk();
    $response->assertJsonCount(1);
    $response->assertJsonFragment(['name' => 'my-service']);
});

it('does not list another team\'s services', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    apiMakeService($otherTeam);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/services');

    $response->assertOk();
    $response->assertJsonCount(0);
});

it('shows a single service by uuid', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiMakeService($team);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/services/{$service->uuid}");

    $response->assertOk();
    $response->assertJsonFragment(['uuid' => $service->uuid]);
});

it('returns 404 for a service belonging to another team', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiMakeService($otherTeam);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/services/{$service->uuid}");

    $response->assertNotFound();
});

it('updates a service field', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiMakeService($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/services/{$service->uuid}", [
        'name' => 'renamed-service',
    ]);

    $response->assertOk();
    expect($service->refresh()->name)->toBe('renamed-service');
});

it('rejects an update with an unknown field', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiMakeService($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/services/{$service->uuid}", [
        'not_a_real_field' => 'value',
    ]);

    $response->assertStatus(422);
});

it('rejects an update to another team\'s service', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiMakeService($otherTeam);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/services/{$service->uuid}", [
        'name' => 'hijacked',
    ]);

    $response->assertNotFound();
});

it('queues a service deletion', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiMakeService($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/services/{$service->uuid}");

    $response->assertOk();
    Queue::assertPushed(DeleteResourceJob::class);
});

it('returns 404 when deleting another team\'s service', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiMakeService($otherTeam);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/services/{$service->uuid}");

    $response->assertNotFound();
});
