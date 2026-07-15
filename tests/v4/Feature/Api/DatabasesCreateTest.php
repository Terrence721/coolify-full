<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

// Tier C (smoke): confirms each of the 8 create_database_* wrapper routes reaches the
// shared create_database() body (proof of correct delegation) via a validation failure.
// create_database()'s own per-engine branching is not characterized here — out of scope.
dataset('databaseCreateRoutes', [
    'postgresql' => ['/api/v1/databases/postgresql'],
    'mysql' => ['/api/v1/databases/mysql'],
    'mariadb' => ['/api/v1/databases/mariadb'],
    'mongodb' => ['/api/v1/databases/mongodb'],
    'redis' => ['/api/v1/databases/redis'],
    'clickhouse' => ['/api/v1/databases/clickhouse'],
    'dragonfly' => ['/api/v1/databases/dragonfly'],
    'keydb' => ['/api/v1/databases/keydb'],
]);

it('rejects a create request missing required fields', function (string $uri) {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write']);

    // Non-empty but incomplete: validateIncomingRequest() 400s on genuinely empty JSON
    // before field validation runs, which isn't what this test means to exercise.
    $response = $this->withHeaders($this->apiHeaders($token))->postJson($uri, ['name' => 'incomplete']);

    $response->assertStatus(422);
})->with('databaseCreateRoutes');

it('returns 404 when the project does not exist', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/v1/databases/postgresql', [
        'project_uuid' => 'nonexistent',
        'environment_name' => 'production',
        'server_uuid' => 'nonexistent',
    ]);

    $response->assertNotFound();
});

it('rejects a create request for another team\'s project', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $otherTeam->id]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/v1/databases/postgresql', [
        'project_uuid' => $project->uuid,
        'environment_name' => 'production',
        'server_uuid' => 'nonexistent',
    ]);

    $response->assertNotFound();
});
