<?php

declare(strict_types=1);

use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandalonePostgresql;
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

function apiActionsMakeDatabase(Team $team, array $attrs = []): StandalonePostgresql
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();

    return StandalonePostgresql::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        ...$attrs,
    ]);
}

it('queues a start for a stopped database', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiActionsMakeDatabase($team, ['status' => 'exited']);
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/databases/{$database->uuid}/start");

    $response->assertOk();
    Queue::assertPushed(JobDecorator::class, fn ($job) => $job->decorates(StartDatabase::class));
});

it('rejects starting an already-running database', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiActionsMakeDatabase($team, ['status' => 'running']);
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/databases/{$database->uuid}/start");

    $response->assertStatus(400);
});

it('returns 404 starting another team\'s database', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiActionsMakeDatabase($otherTeam, ['status' => 'exited']);
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/databases/{$database->uuid}/start");

    $response->assertNotFound();
});

it('queues a stop for a running database', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiActionsMakeDatabase($team, ['status' => 'running']);
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/databases/{$database->uuid}/stop");

    $response->assertOk();
    Queue::assertPushed(JobDecorator::class, fn ($job) => $job->decorates(StopDatabase::class));
});

it('rejects stopping an already-stopped database', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiActionsMakeDatabase($team, ['status' => 'exited']);
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/databases/{$database->uuid}/stop");

    $response->assertStatus(400);
});

it('returns 404 stopping another team\'s database', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiActionsMakeDatabase($otherTeam, ['status' => 'running']);
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/databases/{$database->uuid}/stop");

    $response->assertNotFound();
});

it('queues a restart', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiActionsMakeDatabase($team, ['status' => 'running']);
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/databases/{$database->uuid}/restart");

    $response->assertOk();
    Queue::assertPushed(JobDecorator::class, fn ($job) => $job->decorates(RestartDatabase::class));
});

it('returns 404 restarting another team\'s database', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiActionsMakeDatabase($otherTeam, ['status' => 'running']);
    $token = $this->apiToken($user, $team, ['deploy']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/databases/{$database->uuid}/restart");

    $response->assertNotFound();
});

it('rejects a start with a token missing the deploy ability', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiActionsMakeDatabase($team, ['status' => 'exited']);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/databases/{$database->uuid}/start");

    $response->assertForbidden();
});
