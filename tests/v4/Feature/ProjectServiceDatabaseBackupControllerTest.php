<?php

declare(strict_types=1);

use App\Jobs\DatabaseBackupJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
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

/**
 * Builds a Service + real Postgres-imaged ServiceDatabase (so isBackupSolutionAvailable()
 * is true without needing the custom-type-selection flow) under a full
 * Project/Environment/Server chain. Renamed from the shared "createServiceX" naming
 * convention to avoid Pest's global-function-name collision across test files (all
 * Feature test files share one PHP process - see the Phase 43/44 notes on this).
 */
function createServiceDatabaseFixture(Team $team, array $serviceDatabaseAttributes = []): ServiceDatabase
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->first();

    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    return $service->databases()->create([
        ...$serviceDatabaseAttributes,
        'name' => 'postgres',
        'image' => 'postgres:15',
        'status' => 'running',
    ]);
}

function serviceBackupParams(ServiceDatabase $serviceDatabase): array
{
    $service = $serviceDatabase->service;
    $environment = $service->environment;
    $project = $environment->project;

    return [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'service_uuid' => $service->uuid,
        'stack_service_uuid' => $serviceDatabase->uuid,
    ];
}

it('renders the service database backups page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $serviceDatabase = createServiceDatabaseFixture($team);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.service.database.backups', serviceBackupParams($serviceDatabase)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Service/DatabaseBackups')
        ->where('needsCustomType', false)
        ->where('serviceDatabase.name', 'postgres')
        ->has('scheduledBackups', 0)
    );
});

it('shows the custom-type selection form for a migrated database with no type set', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $serviceDatabase = createServiceDatabaseFixture($team, [
        'image' => 'some/unrecognized-image',
        'is_migrated' => true,
        'custom_type' => null,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.service.database.backups', serviceBackupParams($serviceDatabase)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Service/DatabaseBackups')
        ->where('needsCustomType', true)
    );
});

it('sets the custom database type', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $serviceDatabase = createServiceDatabaseFixture($team, [
        'image' => 'some/unrecognized-image',
        'is_migrated' => true,
        'custom_type' => null,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.service.database.backups.set-type', serviceBackupParams($serviceDatabase)), [
            'custom_type' => 'postgresql',
        ]);

    $response->assertRedirect();
    expect($serviceDatabase->fresh()->custom_type)->toBe('postgresql');
});

it('creates a scheduled backup', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $serviceDatabase = createServiceDatabaseFixture($team);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.service.database.backups.store', serviceBackupParams($serviceDatabase)), [
            'frequency' => '@daily',
            'save_to_s3' => false,
            's3_storage_id' => null,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Scheduled backup created.');
    expect($serviceDatabase->scheduledBackups()->count())->toBe(1);
});

it('rejects an invalid cron expression when creating a scheduled backup', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $serviceDatabase = createServiceDatabaseFixture($team);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.service.database.backups.store', serviceBackupParams($serviceDatabase)), [
            'frequency' => 'not-a-valid-expression',
            'save_to_s3' => false,
            's3_storage_id' => null,
        ]);

    $response->assertSessionHas('error', 'Invalid Cron / Human expression.');
    expect($serviceDatabase->scheduledBackups()->count())->toBe(0);
});

it('shows the selected backup and its executions', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $serviceDatabase = createServiceDatabaseFixture($team);
    $backup = $serviceDatabase->scheduledBackups()->create([
        'frequency' => '@daily',
        'save_s3' => false,
        'team_id' => $team->id,
    ]);
    $execution = $backup->executions()->create(['status' => 'success', 'finished_at' => now()]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.service.database.backups', serviceBackupParams($serviceDatabase)).'?selectedBackupId='.$backup->id);

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('selectedBackupId', $backup->id)
        ->where('selectedBackup.id', $backup->id)
        ->has('executions', 1)
        ->where('executions.0.id', $execution->id)
    );
});

it('updates the backup schedule', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $serviceDatabase = createServiceDatabaseFixture($team);
    $backup = $serviceDatabase->scheduledBackups()->create([
        'frequency' => '@daily',
        'save_s3' => false,
        'team_id' => $team->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('project.service.database.backups.update', [...serviceBackupParams($serviceDatabase), 'backup_id' => $backup->id]), [
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
    expect($backup->fresh()->frequency)->toBe('@weekly');
});

it('deletes a backup schedule with the correct password', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-password')]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $serviceDatabase = createServiceDatabaseFixture($team);
    $backup = $serviceDatabase->scheduledBackups()->create([
        'frequency' => '@daily',
        'save_s3' => false,
        'team_id' => $team->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('project.service.database.backups.destroy', [...serviceBackupParams($serviceDatabase), 'backup_id' => $backup->id]), [
            'password' => 'correct-password',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Scheduled backup deleted.');
    expect($serviceDatabase->scheduledBackups()->count())->toBe(0);
});

it('rejects deleting a backup schedule with an incorrect password', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-password')]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $serviceDatabase = createServiceDatabaseFixture($team);
    $backup = $serviceDatabase->scheduledBackups()->create([
        'frequency' => '@daily',
        'save_s3' => false,
        'team_id' => $team->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('project.service.database.backups.destroy', [...serviceBackupParams($serviceDatabase), 'backup_id' => $backup->id]), [
            'password' => 'wrong-password',
        ]);

    $response->assertSessionHas('error', 'The provided password is incorrect.');
    expect($serviceDatabase->scheduledBackups()->count())->toBe(1);
});

it('dispatches DatabaseBackupJob for backup now', function () {
    Queue::fake();

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $serviceDatabase = createServiceDatabaseFixture($team);
    $backup = $serviceDatabase->scheduledBackups()->create([
        'frequency' => '@daily',
        'save_s3' => false,
        'team_id' => $team->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.service.database.backups.backup-now', [...serviceBackupParams($serviceDatabase), 'backup_id' => $backup->id]));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Backup queued. It will be available in a few minutes.');
    Queue::assertPushed(DatabaseBackupJob::class);
});

it('cleans up failed executions', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $serviceDatabase = createServiceDatabaseFixture($team);
    $backup = $serviceDatabase->scheduledBackups()->create([
        'frequency' => '@daily',
        'save_s3' => false,
        'team_id' => $team->id,
    ]);
    $backup->executions()->create(['status' => 'failed']);
    $backup->executions()->create(['status' => 'success', 'finished_at' => now()]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.service.database.backups.cleanup-failed', [...serviceBackupParams($serviceDatabase), 'backup_id' => $backup->id]));

    $response->assertRedirect();
    expect($backup->executions()->count())->toBe(1);
});

it('deletes an execution with the correct password', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-password')]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $serviceDatabase = createServiceDatabaseFixture($team);
    $backup = $serviceDatabase->scheduledBackups()->create([
        'frequency' => '@daily',
        'save_s3' => false,
        'team_id' => $team->id,
    ]);
    $execution = $backup->executions()->create(['status' => 'success', 'finished_at' => now()]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('project.service.database.backups.execution.destroy', [
            ...serviceBackupParams($serviceDatabase),
            'backup_id' => $backup->id,
            'execution_id' => $execution->id,
        ]), ['password' => 'correct-password']);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Backup deleted.');
    expect($backup->executions()->count())->toBe(0);
});

it('redirects restart to an error when a deployment is already in progress, without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $serviceDatabase = createServiceDatabaseFixture($team);
    $service = $serviceDatabase->service;

    activity()
        ->performedOn($service)
        ->withProperties(['type_uuid' => $service->uuid, 'status' => 'in_progress'])
        ->log('deploying');

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.service.database.backups.restart', serviceBackupParams($serviceDatabase)));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'There is a deployment in progress.');
});

it('redirects to the dashboard for a nonexistent service', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.service.database.backups', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'service_uuid' => 'does-not-exist',
            'stack_service_uuid' => 'does-not-exist',
        ]));

    $response->assertRedirect(route('dashboard'));
});
