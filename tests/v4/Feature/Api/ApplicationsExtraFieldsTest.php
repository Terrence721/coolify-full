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

function applicationsExtraFieldsMakeApplication(Team $team): Application
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
}

it('rejects an unexpected field on create_dockerimage_application', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/v1/applications/dockerimage', [
        'project_uuid' => $project->uuid,
        'environment_name' => 'production',
        'server_uuid' => 'nonexistent',
        'docker_registry_image_name' => 'nginx',
        'unexpected_field' => 'nope',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('unexpected_field');
});

it('rejects an unexpected field on update', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = applicationsExtraFieldsMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}", [
        'name' => 'renamed',
        'unexpected_field' => 'nope',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('unexpected_field');
});

it('still updates an application with only allowed fields', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = applicationsExtraFieldsMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}", [
        'name' => 'renamed',
    ]);

    $response->assertOk();
});

it('rejects an unexpected field on create_env', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = applicationsExtraFieldsMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/applications/{$application->uuid}/envs", [
        'key' => 'NEW_VAR',
        'value' => 'hello',
        'unexpected_field' => 'nope',
    ]);

    $response->assertStatus(422);
});
