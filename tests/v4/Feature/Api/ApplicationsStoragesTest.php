<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fakes\RemoteProcessFake;
use Tests\Support\InteractsWithApiV1;

require_once __DIR__.'/../../../Support/Fakes/model_remote_process_overrides.php';

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
    RemoteProcessFake::reset();
});

function apiStoragesMakeApplication(Team $team, array $attrs = []): Application
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

// storages() GET

it('lists persistent and file storages for an application', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiStoragesMakeApplication($team);
    $volume = LocalPersistentVolume::create([
        'name' => 'vol', 'mount_path' => '/data',
        'resource_id' => $application->id, 'resource_type' => $application->getMorphClass(),
    ]);
    $file = LocalFileVolume::create([
        'fs_path' => '/data/file.txt', 'mount_path' => '/file.txt', 'is_directory' => false,
        'resource_id' => $application->id, 'resource_type' => $application->getMorphClass(),
    ]);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/applications/{$application->uuid}/storages");

    $response->assertOk();
    $response->assertJsonPath('persistent_storages.0.id', $volume->id);
    $response->assertJsonPath('file_storages.0.id', $file->id);
});

it('returns 404 listing storages for another team\'s application', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiStoragesMakeApplication($otherTeam);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/applications/{$application->uuid}/storages");

    $response->assertNotFound();
});

// create_storage()

it('creates a persistent storage', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiStoragesMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/applications/{$application->uuid}/storages", [
        'type' => 'persistent',
        'name' => 'data',
        'mount_path' => '/data',
    ]);

    $response->assertCreated();
    expect(LocalPersistentVolume::where('resource_id', $application->id)->count())->toBe(1);
});

it('creates a file storage', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiStoragesMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/applications/{$application->uuid}/storages", [
        'type' => 'file',
        'mount_path' => '/app/config.txt',
        'content' => 'hello',
    ]);

    $response->assertCreated();
    expect(LocalFileVolume::where('resource_id', $application->id)->count())->toBe(1);
});

it('rejects creating a persistent storage without a name', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiStoragesMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/applications/{$application->uuid}/storages", [
        'type' => 'persistent',
        'mount_path' => '/data',
    ]);

    $response->assertStatus(422);
});

it('rejects an unknown field on create', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiStoragesMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/applications/{$application->uuid}/storages", [
        'type' => 'persistent',
        'name' => 'data',
        'mount_path' => '/data',
        'not_a_real_field' => 'value',
    ]);

    $response->assertStatus(422);
});

it('rejects an invalid storage type', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiStoragesMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/applications/{$application->uuid}/storages", [
        'type' => 'not-a-real-type',
        'mount_path' => '/data',
    ]);

    $response->assertStatus(422);
});

it('rejects a type-specific field not valid for persistent storages', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiStoragesMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/applications/{$application->uuid}/storages", [
        'type' => 'persistent',
        'name' => 'data',
        'mount_path' => '/data',
        'content' => 'not valid for persistent',
    ]);

    $response->assertStatus(422);
});

// update_storage()

it('updates a persistent storage by uuid', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiStoragesMakeApplication($team);
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

it('updates a file storage by id', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiStoragesMakeApplication($team);
    $file = LocalFileVolume::create([
        'fs_path' => '/data/file.txt', 'mount_path' => '/file.txt', 'is_directory' => false,
        'resource_id' => $application->id, 'resource_type' => $application->getMorphClass(),
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}/storages", [
        'id' => $file->id,
        'type' => 'file',
        'content' => 'updated content',
    ]);

    $response->assertOk();
    // Not $file->refresh(): LocalFileVolume's created hook eager-loads its morphTo('resource')
    // relation via a differently-named service() method, which Laravel caches under the key
    // 'resource' (the explicit morph name, not the method name) — refresh()'s relation-reload
    // then tries to call a nonexistent resource() method. A fresh find() sidesteps it.
    expect(LocalFileVolume::find($file->id)->content)->toBe('updated content');
});

it('rejects an update with neither uuid nor id', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiStoragesMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}/storages", [
        'type' => 'persistent',
        'mount_path' => '/data',
    ]);

    $response->assertStatus(422);
});

it('rejects a type-specific field not valid for file storages on update', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiStoragesMakeApplication($team);
    $file = LocalFileVolume::create([
        'fs_path' => '/data/file.txt', 'mount_path' => '/file.txt', 'is_directory' => false,
        'resource_id' => $application->id, 'resource_type' => $application->getMorphClass(),
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}/storages", [
        'id' => $file->id,
        'type' => 'file',
        'name' => 'not valid for file',
    ]);

    $response->assertStatus(422);
});

it('returns 404 updating a storage that does not exist', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiStoragesMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}/storages", [
        'uuid' => 'nonexistent',
        'type' => 'persistent',
        'mount_path' => '/data',
    ]);

    $response->assertNotFound();
});

// delete_storage()

it('deletes a persistent storage', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiStoragesMakeApplication($team);
    $volume = LocalPersistentVolume::create([
        'name' => 'vol', 'mount_path' => '/data',
        'resource_id' => $application->id, 'resource_type' => $application->getMorphClass(),
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/applications/{$application->uuid}/storages/{$volume->uuid}");

    $response->assertOk();
    expect(LocalPersistentVolume::find($volume->id))->toBeNull();
});

it('deletes a file storage, reaching the server over SSH', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiStoragesMakeApplication($team);
    $file = LocalFileVolume::create([
        'fs_path' => '/data/file.txt', 'mount_path' => '/file.txt', 'is_directory' => false,
        'resource_id' => $application->id, 'resource_type' => $application->getMorphClass(),
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/applications/{$application->uuid}/storages/{$file->uuid}");

    $response->assertOk();
    expect(LocalFileVolume::find($file->id))->toBeNull();
    expect(RemoteProcessFake::$instantRemoteProcessCalls)->not->toBeEmpty();
});

it('returns 404 deleting a storage that does not exist', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiStoragesMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/applications/{$application->uuid}/storages/nonexistent-uuid");

    $response->assertNotFound();
});

it('returns 404 deleting a storage for another team\'s application', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiStoragesMakeApplication($otherTeam);
    $volume = LocalPersistentVolume::create([
        'name' => 'vol', 'mount_path' => '/data',
        'resource_id' => $application->id, 'resource_type' => $application->getMorphClass(),
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/applications/{$application->uuid}/storages/{$volume->uuid}");

    $response->assertNotFound();
});
