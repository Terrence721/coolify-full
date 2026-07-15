<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

function apiEnvsMakeApplication(Team $team, array $attrs = []): Application
{
    $server = Server::factory()->create(['team_id' => $team->id]);
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

// envs() GET — Tier A

it('lists env vars, merging in preview envs', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiEnvsMakeApplication($team);
    $application->environment_variables()->create(['key' => 'REGULAR', 'value' => '1', 'is_preview' => false]);
    $application->environment_variables()->create(['key' => 'PREVIEW_ONLY', 'value' => '2', 'is_preview' => true]);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/applications/{$application->uuid}/envs");

    $response->assertOk();
    $response->assertJsonFragment(['key' => 'REGULAR']);
    $response->assertJsonFragment(['key' => 'PREVIEW_ONLY']);
});

it('returns 404 listing envs for another team\'s application', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiEnvsMakeApplication($otherTeam);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/applications/{$application->uuid}/envs");

    $response->assertNotFound();
});

it('hides env values without the read:sensitive ability', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiEnvsMakeApplication($team);
    $application->environment_variables()->create(['key' => 'SECRET', 'value' => 'shh']);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/applications/{$application->uuid}/envs");

    $response->assertOk();
    $response->assertJsonMissingPath('0.value');
});

it('shows env values with the read:sensitive ability', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiEnvsMakeApplication($team);
    $application->environment_variables()->create(['key' => 'SECRET', 'value' => 'shh']);
    $token = $this->apiToken($user, $team, ['read', 'read:sensitive']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/applications/{$application->uuid}/envs");

    $response->assertOk();
    $response->assertJsonFragment(['value' => 'shh']);
});

// update_env_by_uuid()

it('updates an env var by key', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiEnvsMakeApplication($team);
    $env = $application->environment_variables()->create(['key' => 'FOO', 'value' => 'old']);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}/envs", [
        'key' => 'FOO',
        'value' => 'new',
    ]);

    $response->assertCreated();
    expect($env->fresh()->value)->toBe('new');
});

it('rejects updating an env var with an unknown field', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiEnvsMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}/envs", [
        'key' => 'FOO',
        'value' => 'new',
        'not_a_real_field' => 'value',
    ]);

    $response->assertStatus(422);
});

it('returns 404 updating an env var that does not exist', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiEnvsMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}/envs", [
        'key' => 'NONEXISTENT',
        'value' => 'new',
    ]);

    $response->assertNotFound();
});

// create_env()

it('creates an env var', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiEnvsMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/applications/{$application->uuid}/envs", [
        'key' => 'NEW_VAR',
        'value' => 'hello',
    ]);

    $response->assertCreated();
    expect($application->environment_variables()->where('key', 'NEW_VAR')->exists())->toBeTrue();
});

it('rejects creating an env var without a key', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiEnvsMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/applications/{$application->uuid}/envs", [
        'value' => 'hello',
    ]);

    $response->assertStatus(422);
});

it('returns 409 creating an env var that already exists', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiEnvsMakeApplication($team);
    $application->environment_variables()->create(['key' => 'DUPLICATE', 'value' => 'old']);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/applications/{$application->uuid}/envs", [
        'key' => 'DUPLICATE',
        'value' => 'new',
    ]);

    $response->assertStatus(409);
});

// create_bulk_envs()

it('bulk-creates env vars', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiEnvsMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}/envs/bulk", [
        'data' => [
            ['key' => 'BULK_ONE', 'value' => '1'],
            ['key' => 'BULK_TWO', 'value' => '2'],
        ],
    ]);

    $response->assertCreated();
    expect($application->environment_variables()->where('key', 'BULK_ONE')->exists())->toBeTrue();
    expect($application->environment_variables()->where('key', 'BULK_TWO')->exists())->toBeTrue();
});

it('rejects bulk env creation with missing data', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiEnvsMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}/envs/bulk", []);

    $response->assertStatus(400);
});

// delete_env_by_uuid()

it('deletes an env var', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiEnvsMakeApplication($team);
    $env = $application->environment_variables()->create(['key' => 'FOO', 'value' => 'bar']);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/applications/{$application->uuid}/envs/{$env->uuid}");

    $response->assertOk();
    expect($application->environment_variables()->where('key', 'FOO')->exists())->toBeFalse();
});

it('returns 404 deleting an env var that does not exist', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiEnvsMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/applications/{$application->uuid}/envs/nonexistent-uuid");

    $response->assertNotFound();
});
