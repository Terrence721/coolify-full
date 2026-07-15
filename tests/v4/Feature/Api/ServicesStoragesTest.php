<?php

declare(strict_types=1);

use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fakes\RemoteProcessFake;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
    RemoteProcessFake::reset();
});

function apiStoragesMakeService(Team $team): Service
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();

    return Service::factory()->create([
        'environment_id' => $environment->id,
        'destination_type' => StandaloneDocker::class,
    ]);
}

// storages() GET — Service doesn't own storages directly, it aggregates across
// ServiceApplication/ServiceDatabase children and tags each row with resource_uuid/resource_type.

it('aggregates persistent and file storages across application and database children', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiStoragesMakeService($team);
    $app = ServiceApplication::create(['name' => 'app-child', 'service_id' => $service->id]);
    $db = ServiceDatabase::create(['name' => 'db-child', 'service_id' => $service->id]);
    $appVolume = LocalPersistentVolume::create([
        'name' => 'app-vol', 'mount_path' => '/data',
        'resource_id' => $app->id, 'resource_type' => $app->getMorphClass(),
    ]);
    $dbVolume = LocalPersistentVolume::create([
        'name' => 'db-vol', 'mount_path' => '/data',
        'resource_id' => $db->id, 'resource_type' => $db->getMorphClass(),
    ]);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/services/{$service->uuid}/storages");

    $response->assertOk();
    $response->assertJsonFragment(['id' => $appVolume->id, 'resource_uuid' => $app->uuid, 'resource_type' => 'application']);
    $response->assertJsonFragment(['id' => $dbVolume->id, 'resource_uuid' => $db->uuid, 'resource_type' => 'database']);
});

it('returns 404 listing storages for another team\'s service', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiStoragesMakeService($otherTeam);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/services/{$service->uuid}/storages");

    $response->assertNotFound();
});

// create_storage() — requires resolving the target child via resource_uuid first.

it('creates a persistent storage on a named application child', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiStoragesMakeService($team);
    $app = ServiceApplication::create(['name' => 'app-child', 'service_id' => $service->id]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/services/{$service->uuid}/storages", [
        'type' => 'persistent',
        'resource_uuid' => $app->uuid,
        'name' => 'data',
        'mount_path' => '/data',
    ]);

    $response->assertCreated();
    expect(LocalPersistentVolume::where('resource_id', $app->id)->where('resource_type', $app->getMorphClass())->count())->toBe(1);
});

it('rejects creating a storage with a resource_uuid that does not belong to the service', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiStoragesMakeService($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/services/{$service->uuid}/storages", [
        'type' => 'persistent',
        'resource_uuid' => 'nonexistent-child',
        'name' => 'data',
        'mount_path' => '/data',
    ]);

    $response->assertNotFound();
});

it('rejects creating a storage without resource_uuid', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiStoragesMakeService($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/services/{$service->uuid}/storages", [
        'type' => 'persistent',
        'name' => 'data',
        'mount_path' => '/data',
    ]);

    $response->assertStatus(422);
});

it('rejects an unknown field on create', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiStoragesMakeService($team);
    $app = ServiceApplication::create(['name' => 'app-child', 'service_id' => $service->id]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/services/{$service->uuid}/storages", [
        'type' => 'persistent',
        'resource_uuid' => $app->uuid,
        'name' => 'data',
        'mount_path' => '/data',
        'not_a_real_field' => 'value',
    ]);

    $response->assertStatus(422);
});

// update_storage() — must fan out across applications then databases to find the storage.

// Persistent storages owned by a ServiceApplication/ServiceDatabase are unconditionally
// read-only (shouldBeReadOnlyInUI() -> isServiceResource() matches on resource_type alone,
// no docker-compose parsing needed) — so there is no "happy path" mount_path/name/host_path
// update or delete for a persistent storage via the Services endpoint; only
// is_preview_suffix_enabled can ever be changed, and only file storages remain deletable.
// Unlike Application's own storages, this read-only branch IS naturally reachable here.

it('rejects updating a read-only storage owned by an application child', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiStoragesMakeService($team);
    $app = ServiceApplication::create(['name' => 'app-child', 'service_id' => $service->id]);
    $volume = LocalPersistentVolume::create([
        'name' => 'vol', 'mount_path' => '/data',
        'resource_id' => $app->id, 'resource_type' => $app->getMorphClass(),
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/services/{$service->uuid}/storages", [
        'uuid' => $volume->uuid,
        'type' => 'persistent',
        'mount_path' => '/new-data',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('read_only_fields.0', 'mount_path');
});

it('allows updating is_preview_suffix_enabled on a read-only storage', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiStoragesMakeService($team);
    $app = ServiceApplication::create(['name' => 'app-child', 'service_id' => $service->id]);
    $volume = LocalPersistentVolume::create([
        'name' => 'vol', 'mount_path' => '/data',
        'resource_id' => $app->id, 'resource_type' => $app->getMorphClass(),
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/services/{$service->uuid}/storages", [
        'uuid' => $volume->uuid,
        'type' => 'persistent',
        'is_preview_suffix_enabled' => true,
    ]);

    $response->assertOk();
    expect($volume->refresh()->is_preview_suffix_enabled)->toBeTruthy();
});

it('rejects an update with neither uuid nor id', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiStoragesMakeService($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/services/{$service->uuid}/storages", [
        'type' => 'persistent',
        'mount_path' => '/data',
    ]);

    $response->assertStatus(422);
});

it('returns 404 updating a storage that does not exist on any child', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiStoragesMakeService($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/services/{$service->uuid}/storages", [
        'uuid' => 'nonexistent',
        'type' => 'persistent',
        'mount_path' => '/data',
    ]);

    $response->assertNotFound();
});

// delete_storage() — same fan-out, plus the read-only rejection.

it('rejects deleting a read-only persistent storage owned by an application child', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiStoragesMakeService($team);
    $app = ServiceApplication::create(['name' => 'app-child', 'service_id' => $service->id]);
    $volume = LocalPersistentVolume::create([
        'name' => 'vol', 'mount_path' => '/data',
        'resource_id' => $app->id, 'resource_type' => $app->getMorphClass(),
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/services/{$service->uuid}/storages/{$volume->uuid}");

    $response->assertStatus(422);
    expect(LocalPersistentVolume::find($volume->id))->not->toBeNull();
});

it('deletes a file storage owned by an application child, reaching the server over SSH', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiStoragesMakeService($team);
    $app = ServiceApplication::create(['name' => 'app-child', 'service_id' => $service->id]);
    $file = LocalFileVolume::create([
        'fs_path' => '/data/file.txt', 'mount_path' => '/file.txt', 'is_directory' => false,
        'resource_id' => $app->id, 'resource_type' => $app->getMorphClass(),
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/services/{$service->uuid}/storages/{$file->uuid}");

    $response->assertOk();
    expect(LocalFileVolume::find($file->id))->toBeNull();
});

it('rejects deleting a read-only storage', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiStoragesMakeService($team);
    $db = ServiceDatabase::create(['name' => 'db-child', 'service_id' => $service->id]);
    $volume = LocalPersistentVolume::create([
        'name' => 'vol', 'mount_path' => '/data',
        'resource_id' => $db->id, 'resource_type' => $db->getMorphClass(),
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/services/{$service->uuid}/storages/{$volume->uuid}");

    $response->assertStatus(422);
    expect(LocalPersistentVolume::find($volume->id))->not->toBeNull();
});

it('returns 404 deleting a storage that does not exist on any child', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiStoragesMakeService($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/services/{$service->uuid}/storages/nonexistent-uuid");

    $response->assertNotFound();
});

it('returns 404 deleting a storage for another team\'s service', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $service = apiStoragesMakeService($otherTeam);
    $app = ServiceApplication::create(['name' => 'app-child', 'service_id' => $service->id]);
    $volume = LocalPersistentVolume::create([
        'name' => 'vol', 'mount_path' => '/data',
        'resource_id' => $app->id, 'resource_type' => $app->getMorphClass(),
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/services/{$service->uuid}/storages/{$volume->uuid}");

    $response->assertNotFound();
});
