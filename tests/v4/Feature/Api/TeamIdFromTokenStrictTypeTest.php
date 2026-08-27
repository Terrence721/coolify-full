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

// getTeamIdFromToken() ultimately reads PersonalAccessToken::team_id, a plain VARCHAR
// column (the original migration never cast it) with no cast on the model - a real
// Sanctum-authenticated request re-fetches the token from the DB by its hashed value,
// so the value it hands back is genuinely a numeric string, not an int, in production.
// A handful of consumers declare a strict int $teamId parameter under strict_types=1
// and crash with a TypeError on every real request that reaches them.

it('does not crash create_scheduled_task_by_application_uuid() with a strict-int TypeError', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/applications/{$application->uuid}/scheduled-tasks", [
        'name' => 'my-task',
        'command' => 'echo hi',
        'frequency' => '* * * * *',
    ]);

    $response->assertCreated();
});

it('does not crash the deploy endpoint (by_uuids) with a strict-int TypeError', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['deploy'], role: 'admin');

    // A nonexistent uuid still exercises getResourceByUuid($uuid, $teamId) - the
    // TypeError, if present, fires on entry to by_uuids() before this even resolves,
    // regardless of whether a real resource is found.
    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/deploy?uuid=nonexistent-uuid');

    $response->assertStatus(404);
    $response->assertJsonPath('message', 'No resources found.');
});

it('does not crash the deploy endpoint (by_tags) with a strict-int TypeError', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['deploy'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/deploy?tag=nonexistent-tag');

    $response->assertStatus(404);
    $response->assertJsonPath('message', 'No resources found with this tag.');
});
