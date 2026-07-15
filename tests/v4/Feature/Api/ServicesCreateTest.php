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

it('rejects a create request missing required fields', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write']);

    // Non-empty but incomplete: validateIncomingRequest() 400s on genuinely empty JSON
    // before field validation runs, which isn't what this test means to exercise.
    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/v1/services', ['name' => 'incomplete']);

    $response->assertStatus(422);
});

it('rejects a create request providing both type and docker_compose_raw', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/v1/services', [
        'type' => 'plausible',
        'docker_compose_raw' => base64_encode('version: "3"'),
        'project_uuid' => $project->uuid,
        'environment_name' => 'production',
        'server_uuid' => 'nonexistent',
    ]);

    $response->assertStatus(422);
});

it('returns 404 when the project does not exist', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/v1/services', [
        'type' => 'plausible',
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

    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/v1/services', [
        'type' => 'plausible',
        'project_uuid' => $project->uuid,
        'environment_name' => 'production',
        'server_uuid' => 'nonexistent',
    ]);

    $response->assertNotFound();
});
