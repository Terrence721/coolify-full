<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\LocalPersistentVolume;
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

function readOnlyStoragesMakeApplication(Team $team, array $attrs = []): Application
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

// LocalPersistentVolume::isDockerComposeResource() only trusts an already-loaded 'resource'
// relation (its own explicit N+1 guard) — findApiStorageByLookup()/findApiStorageByUuid() in
// ManagesApiResourceStorages didn't set it, so a docker-compose-owned volume's read-only check
// silently evaluated to false through the API, even though the UI treats it as immutable.

it('rejects updating a docker-compose-owned persistent storage via the API', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = readOnlyStoragesMakeApplication($team, ['build_pack' => 'dockercompose']);
    $volume = LocalPersistentVolume::create([
        'name' => 'vol', 'mount_path' => '/data',
        'resource_id' => $application->id, 'resource_type' => $application->getMorphClass(),
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}/storages", [
        'uuid' => $volume->uuid,
        'type' => 'persistent',
        'mount_path' => '/hijacked',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('message', 'This storage is read-only (managed by docker-compose or service definition). Only is_preview_suffix_enabled can be updated.');
    expect($volume->refresh()->mount_path)->toBe('/data');
});

it('rejects deleting a docker-compose-owned persistent storage via the API', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = readOnlyStoragesMakeApplication($team, ['build_pack' => 'dockercompose']);
    $volume = LocalPersistentVolume::create([
        'name' => 'vol', 'mount_path' => '/data',
        'resource_id' => $application->id, 'resource_type' => $application->getMorphClass(),
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/applications/{$application->uuid}/storages/{$volume->uuid}");

    $response->assertStatus(422);
    $response->assertJsonPath('message', 'This storage is read-only (managed by docker-compose or service definition) and cannot be deleted.');
    expect(LocalPersistentVolume::find($volume->id))->not->toBeNull();
});

it('still allows updating a non-compose persistent storage via the API', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = readOnlyStoragesMakeApplication($team);
    $volume = LocalPersistentVolume::create([
        'name' => 'vol', 'mount_path' => '/data',
        'resource_id' => $application->id, 'resource_type' => $application->getMorphClass(),
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}/storages", [
        'uuid' => $volume->uuid,
        'type' => 'persistent',
        'mount_path' => '/new-data',
    ]);

    $response->assertOk();
    expect($volume->refresh()->mount_path)->toBe('/new-data');
});
