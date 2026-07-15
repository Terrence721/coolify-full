<?php

declare(strict_types=1);

use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
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

// ServerFactory defaults private_key_id to 1, a placeholder that only resolves in a
// dev-seeded DB, not a fresh test transaction — routes that dereference $server->privateKey
// (e.g. logs_by_uuid()) need a real row or they 404 with "No query results for model
// [PrivateKey] 1" instead of exercising the actual code under test.
function apiMakeThrowawayKey(Team $team): PrivateKey
{
    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $rsaKey);

    return PrivateKey::create([
        'name' => 'throwaway-key',
        'private_key' => $rsaKey,
        'team_id' => $team->id,
    ]);
}

function apiMakeApplication(Team $team, array $attrs = []): Application
{
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => apiMakeThrowawayKey($team)->id]);
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

it('lists applications owned by the token team', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    apiMakeApplication($team, ['name' => 'my-app']);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/applications');

    $response->assertOk();
    $response->assertJsonCount(1);
    $response->assertJsonFragment(['name' => 'my-app']);
});

it('does not list another team\'s applications', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    apiMakeApplication($otherTeam, ['name' => 'not-mine']);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/applications');

    $response->assertOk();
    $response->assertJsonCount(0);
});

it('shows a single application by uuid', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($team);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/applications/{$application->uuid}");

    $response->assertOk();
    $response->assertJsonFragment(['uuid' => $application->uuid]);
});

it('returns 404 for an application belonging to another team', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($otherTeam);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/applications/{$application->uuid}");

    $response->assertNotFound();
});

it('updates an application field', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}", [
        'name' => 'renamed-app',
    ]);

    $response->assertOk();
    expect($application->refresh()->name)->toBe('renamed-app');
});

it('rejects an update with a non-numeric ports_exposes', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}", [
        'ports_exposes' => 'not-a-number',
    ]);

    $response->assertStatus(422);
});

it('rejects an update to another team\'s application', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($otherTeam);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}", [
        'name' => 'hijacked',
    ]);

    $response->assertNotFound();
});

it('queues an application deletion', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/applications/{$application->uuid}");

    $response->assertOk();
    Queue::assertPushed(DeleteResourceJob::class);
});

it('returns 404 when deleting another team\'s application', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($otherTeam);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/applications/{$application->uuid}");

    $response->assertNotFound();
});

it('returns 404 for logs of another team\'s application', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($otherTeam);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/applications/{$application->uuid}/logs");

    $response->assertNotFound();
});

// logs_by_uuid()'s "not running" 400 path is reached only after a real SSH round-trip
// (getCurrentApplicationContainerStatus() checks the live container list) — same untested
// SSH-touching-happy-path gap documented in docs/smoketest.md, not mocked here.

it('rejects an invalid pull_request_id when deleting a preview', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/applications/{$application->uuid}/previews/not-a-number");

    $response->assertStatus(422);
});

it('returns 404 when deleting a preview that does not exist', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/applications/{$application->uuid}/previews/1");

    $response->assertNotFound();
});
