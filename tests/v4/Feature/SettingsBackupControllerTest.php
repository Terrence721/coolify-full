<?php

declare(strict_types=1);

use App\Jobs\DatabaseBackupJob;
use App\Models\InstanceSettings;
use App\Models\Server;
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

/**
 * Creating a User with id 0 auto-provisions and owns the "Root Team" (id 0) via
 * User::booted()'s created hook - that root-team membership is what isInstanceAdmin()
 * actually checks, matching the established convention across the other Settings tests.
 */
function makeInstanceAdmin(array $overrides = []): User
{
    $user = User::forceCreate(User::factory()->raw(array_merge(['id' => 0], $overrides)));

    // Unlike Team::factory() (which defaults show_boarding to false), the Root Team
    // auto-provisioned by User's created hook keeps the schema default of true,
    // which would otherwise redirect every request here to the onboarding flow.
    Team::find(0)->update(['show_boarding' => false]);

    return $user;
}

function makeFunctionalRootServer(): Server
{
    $server = Server::factory()->create(['id' => 0, 'team_id' => 0]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);

    return $server;
}

function makeCoolifyDbWithBackup(Server $server, array $backupOverrides = []): array
{
    // Server's `created` model event already provisions a StandaloneDocker with id 0
    // for the root server, the same one the real addDatabase() flow attaches to.
    $destination = StandaloneDocker::findOrFail(0);

    $database = StandalonePostgresql::create([
        'id' => 0,
        'name' => 'coolify-db',
        'description' => 'Coolify database',
        'postgres_user' => 'postgres',
        'postgres_password' => 'secret',
        'postgres_db' => 'postgres',
        'status' => 'running',
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $backup = $database->scheduledBackups()->create(array_merge([
        'id' => 0,
        'frequency' => '@daily',
        'save_s3' => false,
        'team_id' => 0,
    ], $backupOverrides));

    return [$database, $backup];
}

it('redirects to the dashboard for a non instance-admin user', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('settings.backup'));

    $response->assertRedirect(route('dashboard'));
});

it('renders the server-not-functional state', function () {
    $user = makeInstanceAdmin();
    Server::factory()->create(['id' => 0, 'team_id' => 0]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => Team::find(0)])
        ->get(route('settings.backup'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('SettingsBackup')
        ->where('serverFunctional', false)
        ->where('database', null)
    );
});

it('renders the no-database state when the server is functional', function () {
    $user = makeInstanceAdmin();
    makeFunctionalRootServer();

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => Team::find(0)])
        ->get(route('settings.backup'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('SettingsBackup')
        ->where('serverFunctional', true)
        ->where('database', null)
        ->has('urls.addDatabase')
    );
});

it('renders the full backup page with executions', function () {
    $user = makeInstanceAdmin();
    $server = makeFunctionalRootServer();
    [$database, $backup] = makeCoolifyDbWithBackup($server);
    $execution = $backup->executions()->create([
        'status' => 'success',
        'finished_at' => now(),
        'database_name' => 'coolify-db',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => Team::find(0)])
        ->get(route('settings.backup'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('SettingsBackup')
        ->where('serverFunctional', true)
        ->where('database.uuid', $database->uuid)
        ->where('backup.id', $backup->id)
        ->has('executions', 1)
        ->where('executions.0.id', $execution->id)
        ->where('executionsCount', 1)
    );
});

it('updates the database identity', function () {
    $user = makeInstanceAdmin();
    $server = makeFunctionalRootServer();
    [$database] = makeCoolifyDbWithBackup($server);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => Team::find(0)])
        ->put(route('settings.backup.update'), [
            'name' => 'coolify-db',
            'description' => 'Updated description',
            'postgres_user' => 'postgres',
            'postgres_password' => 'secret',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Backup updated.');
    expect($database->fresh()->description)->toBe('Updated description');
});

it('updates the backup schedule', function () {
    $user = makeInstanceAdmin();
    $server = makeFunctionalRootServer();
    [, $backup] = makeCoolifyDbWithBackup($server);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => Team::find(0)])
        ->put(route('settings.backup.schedule.update'), [
            'enabled' => true,
            'frequency' => '@weekly',
            'save_s3' => false,
            'disable_local_backup' => false,
            's3_storage_id' => null,
            'databases_to_backup' => null,
            'dump_all' => false,
            'timeout' => 7200,
            'database_backup_retention_amount_locally' => 5,
            'database_backup_retention_days_locally' => 10,
            'database_backup_retention_max_storage_locally' => 0,
            'database_backup_retention_amount_s3' => 0,
            'database_backup_retention_days_s3' => 0,
            'database_backup_retention_max_storage_s3' => 0,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Backup updated successfully.');
    expect($backup->fresh())->frequency->toBe('@weekly')->timeout->toBe(7200);
});

it('rejects an invalid cron expression when updating the backup schedule', function () {
    $user = makeInstanceAdmin();
    $server = makeFunctionalRootServer();
    [, $backup] = makeCoolifyDbWithBackup($server);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => Team::find(0)])
        ->put(route('settings.backup.schedule.update'), [
            'enabled' => true,
            'frequency' => 'not-a-valid-expression',
            'save_s3' => false,
            'disable_local_backup' => false,
            'dump_all' => false,
            'timeout' => 3600,
            'database_backup_retention_amount_locally' => 0,
            'database_backup_retention_days_locally' => 0,
            'database_backup_retention_max_storage_locally' => 0,
            'database_backup_retention_amount_s3' => 0,
            'database_backup_retention_days_s3' => 0,
            'database_backup_retention_max_storage_s3' => 0,
        ]);

    $response->assertSessionHas('error', 'Invalid Cron / Human expression');
    expect($backup->fresh()->frequency)->toBe('@daily');
});

it('dispatches DatabaseBackupJob for backup now', function () {
    Queue::fake();

    $user = makeInstanceAdmin();
    $server = makeFunctionalRootServer();
    makeCoolifyDbWithBackup($server);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => Team::find(0)])
        ->post(route('settings.backup.backup-now'));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Backup queued. It will be available in a few minutes.');
    Queue::assertPushed(DatabaseBackupJob::class);
});

it('cleans up failed executions', function () {
    $user = makeInstanceAdmin();
    $server = makeFunctionalRootServer();
    [, $backup] = makeCoolifyDbWithBackup($server);
    $backup->executions()->create(['status' => 'failed']);
    $backup->executions()->create(['status' => 'success', 'finished_at' => now()]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => Team::find(0)])
        ->post(route('settings.backup.cleanup-failed'));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Failed backups cleaned up.');
    expect($backup->executions()->count())->toBe(1);
    expect($backup->executions()->where('status', 'failed')->exists())->toBeFalse();
});

it('cleans up deleted executions', function () {
    $user = makeInstanceAdmin();
    $server = makeFunctionalRootServer();
    [, $backup] = makeCoolifyDbWithBackup($server);
    $backup->executions()->create(['status' => 'success', 'finished_at' => now(), 'local_storage_deleted' => true]);
    $backup->executions()->create(['status' => 'success', 'finished_at' => now(), 'local_storage_deleted' => false]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => Team::find(0)])
        ->post(route('settings.backup.cleanup-deleted'));

    $response->assertRedirect();
    expect($backup->executions()->count())->toBe(1);
});

it('deletes an execution with the correct password', function () {
    $user = makeInstanceAdmin(['password' => bcrypt('correct-password')]);
    $server = makeFunctionalRootServer();
    [, $backup] = makeCoolifyDbWithBackup($server);
    $execution = $backup->executions()->create(['status' => 'success', 'finished_at' => now()]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => Team::find(0)])
        ->delete(route('settings.backup.execution.destroy', ['execution_id' => $execution->id]), [
            'password' => 'correct-password',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Backup deleted.');
    expect($backup->executions()->find($execution->id))->toBeNull();
});

it('rejects deleting an execution with an incorrect password', function () {
    $user = makeInstanceAdmin(['password' => bcrypt('correct-password')]);
    $server = makeFunctionalRootServer();
    [, $backup] = makeCoolifyDbWithBackup($server);
    $execution = $backup->executions()->create(['status' => 'success', 'finished_at' => now()]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => Team::find(0)])
        ->delete(route('settings.backup.execution.destroy', ['execution_id' => $execution->id]), [
            'password' => 'wrong-password',
        ]);

    $response->assertSessionHas('error', 'The provided password is incorrect.');
    expect($backup->executions()->find($execution->id))->not->toBeNull();
});
