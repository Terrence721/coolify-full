<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regression coverage for a real bug (code review, issue #70): the download.backup route
 * skipped its own team-ownership check entirely whenever the caller's currently-selected team
 * was team 0 (the root team - a real, ordinary team on any self-hosted instance, not exclusive
 * to a special "root user"). Any admin/owner of team 0, with team 0 selected, could download any
 * OTHER team's real database backup by guessing a small sequential integer execution ID - a
 * genuine cross-tenant data exfiltration, not a hypothetical.
 */
beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function makeBackupExecutionForTeam(Team $team): ScheduledDatabaseBackupExecution
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->destinations()->first();

    $database = StandalonePostgresql::create([
        'name' => 'victim-postgres',
        'postgres_password' => 'secret',
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'environment_id' => $environment->id,
        'status' => 'running',
    ]);

    $backup = $database->scheduledBackups()->create([
        'frequency' => '@daily',
        'save_s3' => false,
        'team_id' => $team->id,
    ]);

    return $backup->executions()->create([
        'status' => 'success',
        'finished_at' => now(),
        'filename' => 'backups/victim-dump.sql',
    ]);
}

it('rejects downloading another team\'s backup even when the root team (0) is currently selected', function () {
    $rootUser = User::forceCreate(User::factory()->raw(['id' => 0]));
    $rootTeam = $rootUser->teams()->first();
    $rootTeam->update(['show_boarding' => false]);

    $victimTeam = Team::factory()->create();
    $execution = makeBackupExecutionForTeam($victimTeam);

    $response = $this->actingAs($rootUser)
        ->withSession(['currentTeam' => $rootTeam])
        ->get(route('download.backup', ['executionId' => $execution->id]));

    $response->assertStatus(403);
    $response->assertJson(['message' => 'Permission denied.']);
});

it('rejects downloading another team\'s backup for an ordinary (non-root) admin too', function () {
    $user = User::factory()->create();
    $ownTeam = Team::factory()->create();
    $ownTeam->members()->attach($user, ['role' => 'admin']);

    $victimTeam = Team::factory()->create();
    $execution = makeBackupExecutionForTeam($victimTeam);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $ownTeam])
        ->get(route('download.backup', ['executionId' => $execution->id]));

    $response->assertStatus(403);
    $response->assertJson(['message' => 'Permission denied.']);
});

it('does not reject a request for a backup that actually belongs to the currently selected team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $execution = makeBackupExecutionForTeam($team);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('download.backup', ['executionId' => $execution->id]));

    // No real SFTP server is reachable in this test environment, so this can't assert a full
    // successful download - only that authorization itself did not reject it (a same-team
    // request must never get the cross-team denial response).
    $response->assertStatus(500);
    $response->assertJson(['message' => 'Failed to download backup.']);
});
