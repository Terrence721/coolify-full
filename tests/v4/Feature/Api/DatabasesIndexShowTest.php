<?php

declare(strict_types=1);

use App\Jobs\DeleteResourceJob;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

function apiMakeDatabase(Team $team, array $attrs = []): StandalonePostgresql
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

it('lists databases owned by the token team', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    apiMakeDatabase($team, ['name' => 'my-db']);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/databases');

    $response->assertOk();
    $response->assertJsonCount(1);
    $response->assertJsonFragment(['name' => 'my-db']);
});

it('does not list another team\'s databases', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    apiMakeDatabase($otherTeam);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/databases');

    $response->assertOk();
    $response->assertJsonCount(0);
});

it('shows a single database by uuid', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiMakeDatabase($team);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/databases/{$database->uuid}");

    $response->assertOk();
    $response->assertJsonFragment(['uuid' => $database->uuid]);
});

it('returns 404 for a database belonging to another team', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiMakeDatabase($otherTeam);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/databases/{$database->uuid}");

    $response->assertNotFound();
});

it('updates a database field', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiMakeDatabase($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/databases/{$database->uuid}", [
        'name' => 'renamed-db',
    ]);

    $response->assertOk();
    expect($database->refresh()->name)->toBe('renamed-db');
});

it('rejects an update with a non-numeric limits_memory_swappiness', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiMakeDatabase($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/databases/{$database->uuid}", [
        'limits_memory_swappiness' => 'not-a-number',
    ]);

    $response->assertStatus(422);
});

it('rejects an update to another team\'s database', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiMakeDatabase($otherTeam);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/databases/{$database->uuid}", [
        'name' => 'hijacked',
    ]);

    $response->assertNotFound();
});

it('queues a database deletion', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiMakeDatabase($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/databases/{$database->uuid}");

    $response->assertOk();
    Queue::assertPushed(DeleteResourceJob::class);
});

it('returns 404 when deleting another team\'s database', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiMakeDatabase($otherTeam);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/databases/{$database->uuid}");

    $response->assertNotFound();
});
