<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function makeStorage(int $teamId, array $attributes = []): S3Storage
{
    return S3Storage::create(array_merge([
        'name' => 'Backups',
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'bucket' => 'my-bucket',
        'endpoint' => 'https://s3.us-east-1.amazonaws.com',
        'team_id' => $teamId,
        'is_usable' => true,
    ], $attributes));
}

it('renders the storage show Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $storage = makeStorage($team->id);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('storage.show', ['storage_uuid' => $storage->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Storage/Show')
        ->where('storage.name', 'Backups')
        ->where('canUpdate', true)
        ->where('canDelete', true)
    );
});

it('returns 404 for a storage owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $storage = makeStorage($otherTeam->id);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('storage.show', ['storage_uuid' => $storage->uuid]));

    $response->assertNotFound();
});

it('rejects an unsafe endpoint on update without touching the network', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $storage = makeStorage($team->id);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('storage.update', ['storage_uuid' => $storage->uuid]), [
            'region' => 'us-east-1',
            'key' => 'k',
            'secret' => 's',
            'bucket' => 'b',
            'endpoint' => 'https://localhost/evil',
        ]);

    $response->assertSessionHasErrors(['endpoint']);
});

it('deletes a storage without touching the network', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $storage = makeStorage($team->id);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('storage.destroy', ['storage_uuid' => $storage->uuid]));

    $response->assertRedirect(route('storage.index'));
    expect(S3Storage::find($storage->id))->toBeNull();
});

it('renders the storage resources Inertia page, including a backup with a deleted database', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $storage = makeStorage($team->id);

    ScheduledDatabaseBackup::create([
        'uuid' => (string) new Cuid2,
        'team_id' => $team->id,
        'enabled' => true,
        'save_s3' => true,
        'frequency' => '0 0 * * *',
        'database_type' => 'App\\Models\\StandalonePostgresql',
        'database_id' => 999999,
        's3_storage_id' => $storage->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('storage.resources', ['storage_uuid' => $storage->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Storage/Resources')
        ->has('backups', 1)
        ->where('backups.0.databaseName', 'Deleted database')
        ->where('backups.0.resourceLink', null)
    );
});

it('disables S3 for a backup schedule without touching the network', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $storage = makeStorage($team->id);
    $backup = ScheduledDatabaseBackup::create([
        'uuid' => (string) new Cuid2,
        'team_id' => $team->id,
        'enabled' => true,
        'save_s3' => true,
        'frequency' => '0 0 * * *',
        'database_type' => 'App\\Models\\StandalonePostgresql',
        'database_id' => 999999,
        's3_storage_id' => $storage->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('storage.resources.disable-s3', ['storage_uuid' => $storage->uuid, 'backup_id' => $backup->id]));

    $response->assertRedirect();
    expect($backup->fresh())->save_s3->toBeFalsy();
    expect($backup->fresh()->s3_storage_id)->toBeNull();
});

it('moves a backup to a different storage without touching the network', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $storage = makeStorage($team->id);
    $otherStorage = makeStorage($team->id, ['name' => 'Other Backups']);
    $backup = ScheduledDatabaseBackup::create([
        'uuid' => (string) new Cuid2,
        'team_id' => $team->id,
        'enabled' => true,
        'save_s3' => true,
        'frequency' => '0 0 * * *',
        'database_type' => 'App\\Models\\StandalonePostgresql',
        'database_id' => 999999,
        's3_storage_id' => $storage->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('storage.resources.move-backup', ['storage_uuid' => $storage->uuid, 'backup_id' => $backup->id]), [
            'new_storage_id' => $otherStorage->id,
        ]);

    $response->assertRedirect();
    expect($backup->fresh()->s3_storage_id)->toBe($otherStorage->id);
});

it('rejects moving a backup to the same storage', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $storage = makeStorage($team->id);
    $backup = ScheduledDatabaseBackup::create([
        'uuid' => (string) new Cuid2,
        'team_id' => $team->id,
        'enabled' => true,
        'save_s3' => true,
        'frequency' => '0 0 * * *',
        'database_type' => 'App\\Models\\StandalonePostgresql',
        'database_id' => 999999,
        's3_storage_id' => $storage->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('storage.resources.move-backup', ['storage_uuid' => $storage->uuid, 'backup_id' => $backup->id]), [
            'new_storage_id' => $storage->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'No change. The backup is already using this storage.');
});
