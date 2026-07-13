<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function storagesActingAs(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);

    return $user;
}

function storagesMakePostgres(Team $team): StandalonePostgresql
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);

    return StandalonePostgresql::create([
        'name' => 'test-postgres',
        'postgres_password' => 'secret',
        'destination_id' => $server->destinations()->first()->id,
        'destination_type' => StandaloneDocker::class,
        'environment_id' => $project->environments()->first()->id,
        'status' => 'running',
    ]);
}

function storagesParams($resource): array
{
    $key = $resource instanceof Service ? 'service_uuid' : 'database_uuid';

    return [
        'project_uuid' => $resource->environment->project->uuid,
        'environment_uuid' => $resource->environment->uuid,
        $key => $resource->uuid,
    ];
}

it('renders the database persistent-storage tab with volumes and file mounts', function () {
    Queue::fake();
    $team = Team::factory()->create();
    storagesActingAs($team);
    $database = storagesMakePostgres($team);
    LocalPersistentVolume::create([
        'name' => $database->uuid.'-data',
        'mount_path' => '/var/lib/postgresql/data',
        'resource_id' => $database->id,
        'resource_type' => $database->getMorphClass(),
    ]);
    LocalFileVolume::create([
        'fs_path' => '/data/coolify/databases/'.$database->uuid.'/init.sql',
        'mount_path' => '/docker-entrypoint-initdb.d/init.sql',
        'content' => 'SELECT 1;',
        'is_directory' => false,
        'resource_id' => $database->id,
        'resource_type' => $database->getMorphClass(),
    ]);

    $response = $this->get(route('project.database.persistent-storage', storagesParams($database)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Database/Configuration')
        ->where('tab', 'persistent-storage')
        ->has('sections', 1)
        ->has('sections.0.volumes', 2) // the model auto-provisions a default data volume on create
        ->has('sections.0.files', 1)
        ->where('canAddMounts', true)
    );
});

it('adds a volume with the resource uuid prefix', function () {
    $team = Team::factory()->create();
    storagesActingAs($team);
    $database = storagesMakePostgres($team);

    $response = $this->post(route('project.database.storages.volume.store', storagesParams($database)), [
        'name' => 'my-volume',
        'mount_path' => '/data',
        'host_path' => null,
    ]);

    $response->assertRedirect();
    $volume = $database->persistentStorages()->where('mount_path', '/data')->firstOrFail();
    expect($volume->name)->toBe($database->uuid.'-my-volume');
});

it('rejects an unsafe volume name', function () {
    $team = Team::factory()->create();
    storagesActingAs($team);
    $database = storagesMakePostgres($team);

    $response = $this->post(route('project.database.storages.volume.store', storagesParams($database)), [
        'name' => 'bad name; rm -rf /',
        'mount_path' => '/data',
    ]);

    $response->assertSessionHasErrors('name');
    // Only the auto-provisioned default data volume exists
    expect($database->persistentStorages()->count())->toBe(1);
});

it('updates a volume', function () {
    $team = Team::factory()->create();
    storagesActingAs($team);
    $database = storagesMakePostgres($team);
    $volume = LocalPersistentVolume::create([
        'name' => $database->uuid.'-data',
        'mount_path' => '/old',
        'resource_id' => $database->id,
        'resource_type' => $database->getMorphClass(),
    ]);

    $this->patch(route('project.database.storages.volume.update', [...storagesParams($database), 'volume_id' => $volume->id]), [
        'name' => $database->uuid.'-data',
        'mount_path' => '/new',
        'host_path' => '/host',
    ]);

    $volume->refresh();
    expect($volume->mount_path)->toBe('/new');
    expect($volume->host_path)->toBe('/host');
});

it('deletes a volume only with the correct password', function () {
    $team = Team::factory()->create();
    storagesActingAs($team);
    $database = storagesMakePostgres($team);
    $volume = LocalPersistentVolume::create([
        'name' => $database->uuid.'-data',
        'mount_path' => '/data',
        'resource_id' => $database->id,
        'resource_type' => $database->getMorphClass(),
    ]);

    $this->delete(route('project.database.storages.volume.destroy', [...storagesParams($database), 'volume_id' => $volume->id]), [
        'password' => 'wrong',
    ])->assertSessionHas('error');
    expect(LocalPersistentVolume::find($volume->id))->not->toBeNull();

    $this->delete(route('project.database.storages.volume.destroy', [...storagesParams($database), 'volume_id' => $volume->id]), [
        'password' => 'password',
    ]);
    expect(LocalPersistentVolume::find($volume->id))->toBeNull();
});

it('adds a file mount under the database configuration directory', function () {
    Queue::fake();
    $team = Team::factory()->create();
    storagesActingAs($team);
    $database = storagesMakePostgres($team);

    $this->post(route('project.database.storages.file.store', storagesParams($database)), [
        'file_storage_path' => 'etc/config.conf',
        'file_storage_content' => 'setting=1',
    ]);

    $file = $database->fileStorages()->firstOrFail();
    expect($file->mount_path)->toBe('/etc/config.conf');
    expect($file->fs_path)->toContain($database->uuid.'/etc/config.conf');
    expect((bool) $file->is_directory)->toBeFalse();
});

it('rejects a file mount path with shell metacharacters', function () {
    $team = Team::factory()->create();
    storagesActingAs($team);
    $database = storagesMakePostgres($team);

    $response = $this->post(route('project.database.storages.file.store', storagesParams($database)), [
        'file_storage_path' => '/etc/config.conf; rm -rf /',
    ]);

    $response->assertSessionHas('error');
    expect($database->fileStorages()->count())->toBe(0);
});

it('adds a directory mount', function () {
    Queue::fake();
    $team = Team::factory()->create();
    storagesActingAs($team);
    $database = storagesMakePostgres($team);

    $this->post(route('project.database.storages.directory.store', storagesParams($database)), [
        'source' => '/data/coolify/custom',
        'destination' => 'etc/nginx',
    ]);

    $directory = $database->fileStorages()->firstOrFail();
    expect((bool) $directory->is_directory)->toBeTrue();
    expect($directory->mount_path)->toBe('/etc/nginx');
    expect($directory->fs_path)->toBe('/data/coolify/custom');
});

it('deletes a file mount without touching the server when permanent deletion is off', function () {
    Queue::fake();
    $team = Team::factory()->create();
    storagesActingAs($team);
    $database = storagesMakePostgres($team);
    $file = LocalFileVolume::create([
        'fs_path' => '/data/coolify/databases/'.$database->uuid.'/file.txt',
        'mount_path' => '/file.txt',
        'is_directory' => false,
        'resource_id' => $database->id,
        'resource_type' => $database->getMorphClass(),
    ]);

    $this->delete(route('project.database.storages.file.destroy', [...storagesParams($database), 'file_id' => $file->id]), [
        'password' => 'password',
        'permanently_delete' => false,
    ]);

    expect(LocalFileVolume::find($file->id))->toBeNull();
});

it('renders the service storages tab with one section per compose child', function () {
    $team = Team::factory()->create();
    storagesActingAs($team);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $service = Service::factory()->create([
        'name' => 'test-service',
        'environment_id' => $project->environments()->first()->id,
        'server_id' => $server->id,
        'destination_id' => $server->destinations()->first()->id,
        'destination_type' => StandaloneDocker::class,
    ]);
    $child = ServiceApplication::create(['name' => 'app-child', 'service_id' => $service->id]);
    LocalPersistentVolume::create([
        'name' => 'child-volume',
        'mount_path' => '/data',
        'resource_id' => $child->id,
        'resource_type' => $child->getMorphClass(),
    ]);

    $response = $this->get(route('project.service.storages', storagesParams($service)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Service/Configuration')
        ->where('tab', 'storages')
        ->where('canAddMounts', false)
        ->has('sections', 1)
        ->where('sections.0.name', 'App Child')
        ->has('sections.0.volumes', 1)
        ->where('sections.0.volumes.0.isReadOnly', true)
    );
});

it('404s a volume update whose volume belongs to another resource', function () {
    $team = Team::factory()->create();
    storagesActingAs($team);
    $database = storagesMakePostgres($team);
    $otherDatabase = storagesMakePostgres($team);
    $foreignVolume = LocalPersistentVolume::create([
        'name' => $otherDatabase->uuid.'-data',
        'mount_path' => '/data',
        'resource_id' => $otherDatabase->id,
        'resource_type' => $otherDatabase->getMorphClass(),
    ]);

    $response = $this->patch(route('project.database.storages.volume.update', [...storagesParams($database), 'volume_id' => $foreignVolume->id]), [
        'name' => 'hijacked',
        'mount_path' => '/data',
    ]);

    $response->assertStatus(404);
    expect($foreignVolume->fresh()->name)->toBe($otherDatabase->uuid.'-data');
});
