<?php

declare(strict_types=1);

use App\Jobs\DatabaseBackupJob;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function makeTestPostgresWithBackup(Team $team, Server $server, array $backupOverrides = []): array
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->destinations()->first();

    $database = StandalonePostgresql::create([
        'name' => 'test-postgres',
        'postgres_password' => 'secret',
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'environment_id' => $environment->id,
        'status' => 'running',
    ]);

    $backup = $database->scheduledBackups()->create(array_merge([
        'frequency' => '@daily',
        'save_s3' => false,
        'team_id' => $team->id,
    ], $backupOverrides));

    return [$database, $backup];
}

function executionRouteParams(StandalonePostgresql $database, ScheduledDatabaseBackup $backup): array
{
    $environment = $database->environment;
    $project = $environment->project;

    return [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'database_uuid' => $database->uuid,
        'backup_uuid' => $backup->uuid,
    ];
}

it('renders the backup execution page with executions', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    [$database, $backup] = makeTestPostgresWithBackup($team, $server);
    $execution = $backup->executions()->create([
        'status' => 'success',
        'finished_at' => now(),
        'database_name' => 'appdb',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.database.backup.execution', executionRouteParams($database, $backup)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Database/Backup/Execution')
        ->where('backup.id', $backup->id)
        ->has('executions', 1)
        ->where('executions.0.id', $execution->id)
        ->where('executionsCount', 1)
    );
});

it('redirects to the dashboard for a backup that does not belong to the database', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    [$database] = makeTestPostgresWithBackup($team, $server);
    $params = executionRouteParams($database, $database->scheduledBackups()->first());
    $params['backup_uuid'] = 'not-a-real-uuid';

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.database.backup.execution', $params));

    $response->assertRedirect(route('dashboard'));
});

it('updates the backup schedule', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    [$database, $backup] = makeTestPostgresWithBackup($team, $server);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('project.database.backup.update', executionRouteParams($database, $backup)), [
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
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    [$database, $backup] = makeTestPostgresWithBackup($team, $server);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('project.database.backup.update', executionRouteParams($database, $backup)), [
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

it('deletes the backup schedule with the correct password', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-password')]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    [$database, $backup] = makeTestPostgresWithBackup($team, $server);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('project.database.backup.destroy', executionRouteParams($database, $backup)), [
            'password' => 'correct-password',
        ]);

    $response->assertRedirect(route('project.database.backup.index', [
        'project_uuid' => $database->environment->project->uuid,
        'environment_uuid' => $database->environment->uuid,
        'database_uuid' => $database->uuid,
    ]));
    expect(ScheduledDatabaseBackup::find($backup->id))->toBeNull();
});

it('rejects deleting the backup schedule with an incorrect password', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-password')]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    [$database, $backup] = makeTestPostgresWithBackup($team, $server);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('project.database.backup.destroy', executionRouteParams($database, $backup)), [
            'password' => 'wrong-password',
        ]);

    $response->assertSessionHas('error', 'The provided password is incorrect.');
    expect(ScheduledDatabaseBackup::find($backup->id))->not->toBeNull();
});

it('dispatches DatabaseBackupJob for backup now', function () {
    Queue::fake();

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    [$database, $backup] = makeTestPostgresWithBackup($team, $server);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.database.backup.backup-now', executionRouteParams($database, $backup)));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Backup queued. It will be available in a few minutes.');
    Queue::assertPushed(DatabaseBackupJob::class);
});

it('cleans up failed executions', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    [$database, $backup] = makeTestPostgresWithBackup($team, $server);
    $backup->executions()->create(['status' => 'failed']);
    $backup->executions()->create(['status' => 'success', 'finished_at' => now()]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.database.backup.cleanup-failed', executionRouteParams($database, $backup)));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Failed backups cleaned up.');
    expect($backup->executions()->count())->toBe(1);
    expect($backup->executions()->where('status', 'failed')->exists())->toBeFalse();
});

it('cleans up deleted executions', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    [$database, $backup] = makeTestPostgresWithBackup($team, $server);
    $backup->executions()->create(['status' => 'success', 'finished_at' => now(), 'local_storage_deleted' => true]);
    $backup->executions()->create(['status' => 'success', 'finished_at' => now(), 'local_storage_deleted' => false]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.database.backup.cleanup-deleted', executionRouteParams($database, $backup)));

    $response->assertRedirect();
    expect($backup->executions()->count())->toBe(1);
});

it('deletes an execution with the correct password', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-password')]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    [$database, $backup] = makeTestPostgresWithBackup($team, $server);
    $execution = $backup->executions()->create(['status' => 'success', 'finished_at' => now()]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('project.database.backup.execution.destroy', [
            ...executionRouteParams($database, $backup),
            'execution_id' => $execution->id,
        ]), ['password' => 'correct-password']);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Backup deleted.');
    expect(ScheduledDatabaseBackupExecution::find($execution->id))->toBeNull();
});

it('rejects deleting an execution with an incorrect password', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-password')]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    [$database, $backup] = makeTestPostgresWithBackup($team, $server);
    $execution = $backup->executions()->create(['status' => 'success', 'finished_at' => now()]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('project.database.backup.execution.destroy', [
            ...executionRouteParams($database, $backup),
            'execution_id' => $execution->id,
        ]), ['password' => 'wrong-password']);

    $response->assertSessionHas('error', 'The provided password is incorrect.');
    expect(ScheduledDatabaseBackupExecution::find($execution->id))->not->toBeNull();
});
