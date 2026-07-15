<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

function apiBackupsMakeDatabase(Team $team, array $attrs = []): StandalonePostgresql
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();

    return StandalonePostgresql::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        ...$attrs,
    ]);
}

it('creates a backup configuration', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiBackupsMakeDatabase($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/databases/{$database->uuid}/backups", [
        'frequency' => '0 0 * * *',
        'enabled' => true,
    ]);

    $response->assertCreated();
    expect(ScheduledDatabaseBackup::where('database_id', $database->id)->count())->toBe(1);
});

it('rejects a backup configuration with an invalid cron expression', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiBackupsMakeDatabase($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/databases/{$database->uuid}/backups", [
        'frequency' => 'not-a-cron-expression',
    ]);

    $response->assertStatus(422);
});

it('returns 404 creating a backup for another team\'s database', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiBackupsMakeDatabase($otherTeam);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/databases/{$database->uuid}/backups", [
        'frequency' => '0 0 * * *',
    ]);

    $response->assertNotFound();
});

it('lists backup configurations for a database', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiBackupsMakeDatabase($team);
    $backup = ScheduledDatabaseBackup::create([
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $team->id,
        'frequency' => '0 0 * * *',
    ]);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/databases/{$database->uuid}/backups");

    $response->assertOk();
    $response->assertJsonFragment(['uuid' => $backup->uuid]);
});

it('updates a backup configuration', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiBackupsMakeDatabase($team);
    $backup = ScheduledDatabaseBackup::create([
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $team->id,
        'frequency' => '0 0 * * *',
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/databases/{$database->uuid}/backups/{$backup->uuid}", [
        'enabled' => false,
    ]);

    $response->assertOk();
    // ScheduledDatabaseBackup::enabled has no boolean cast (unlike its ScheduledTask
    // sibling), so under SQLite it comes back as a raw 0/1, not a real PHP bool.
    expect($backup->refresh()->enabled)->toBeFalsy();
});

it('rejects a backup update with an invalid cron expression', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiBackupsMakeDatabase($team);
    $backup = ScheduledDatabaseBackup::create([
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $team->id,
        'frequency' => '0 0 * * *',
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/databases/{$database->uuid}/backups/{$backup->uuid}", [
        'frequency' => 'not-a-cron-expression',
    ]);

    $response->assertStatus(422);
});

it('deletes a backup configuration', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiBackupsMakeDatabase($team);
    $backup = ScheduledDatabaseBackup::create([
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $team->id,
        'frequency' => '0 0 * * *',
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/databases/{$database->uuid}/backups/{$backup->uuid}");

    $response->assertOk();
    expect(ScheduledDatabaseBackup::find($backup->id))->toBeNull();
});

it('returns 404 deleting a backup configuration that does not exist', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiBackupsMakeDatabase($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/databases/{$database->uuid}/backups/nonexistent-uuid");

    $response->assertNotFound();
});

it('lists backup executions for a configuration', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiBackupsMakeDatabase($team);
    $backup = ScheduledDatabaseBackup::create([
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $team->id,
        'frequency' => '0 0 * * *',
    ]);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/databases/{$database->uuid}/backups/{$backup->uuid}/executions");

    $response->assertOk();
});

it('returns 404 deleting an execution that does not exist', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiBackupsMakeDatabase($team);
    $backup = ScheduledDatabaseBackup::create([
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $team->id,
        'frequency' => '0 0 * * *',
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/databases/{$database->uuid}/backups/{$backup->uuid}/executions/nonexistent-uuid");

    $response->assertNotFound();
});
