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

it('rejects an unexpected field on create', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/v1/projects', [
        'name' => 'my-project',
        'unexpected_field' => 'nope',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('unexpected_field');
});

it('still creates a project with only allowed fields', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/v1/projects', [
        'name' => 'my-project',
        'description' => 'a project',
    ]);

    $response->assertCreated();
});

it('rejects an unexpected field on update', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/projects/{$project->uuid}", [
        'name' => 'renamed',
        'unexpected_field' => 'nope',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('unexpected_field');
});

it('still updates a project with only allowed fields', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/projects/{$project->uuid}", [
        'name' => 'renamed',
    ]);

    $response->assertStatus(201);
});

it('rejects an unexpected field on create_environment', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/projects/{$project->uuid}/environments", [
        'name' => 'staging',
        'unexpected_field' => 'nope',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('unexpected_field');
});

it('still creates an environment with only allowed fields', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/projects/{$project->uuid}/environments", [
        'name' => 'staging',
    ]);

    $response->assertCreated();
});
