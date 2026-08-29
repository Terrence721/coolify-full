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

function databasesExtraFieldsMakeDatabase(Team $team): StandalonePostgresql
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();

    return StandalonePostgresql::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
    ]);
}

// create_database() — top-level check against the full cross-type allowedFields union.
it('rejects a field unknown to every database type', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/v1/databases/postgresql', [
        'project_uuid' => $project->uuid,
        'environment_name' => 'production',
        'server_uuid' => 'nonexistent',
        'totally_bogus_field' => 'nope',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('totally_bogus_field');
});

// create_database() — per-type block narrows allowedFields further; a field that belongs
// to a different engine (mysql_user) is still rejected when creating a postgresql database.
// Needs a real server_uuid: the top-level check passes (mysql_user is in the cross-type
// union), so this must reach the postgresql-specific block rather than 404 on server lookup.
it('rejects a field that belongs to a different database engine', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/v1/databases/postgresql', [
        'project_uuid' => $project->uuid,
        'environment_name' => 'production',
        'server_uuid' => $server->uuid,
        'mysql_user' => 'nope',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('mysql_user');
});

it('rejects an unexpected field on update', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = databasesExtraFieldsMakeDatabase($team);
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/databases/{$database->uuid}", [
        'name' => 'renamed',
        'unexpected_field' => 'nope',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('unexpected_field');
});

it('still updates a database with only allowed fields', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = databasesExtraFieldsMakeDatabase($team);
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/databases/{$database->uuid}", [
        'name' => 'renamed',
    ]);

    $response->assertOk();
});

it('rejects an unexpected field on create_backup', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = databasesExtraFieldsMakeDatabase($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/databases/{$database->uuid}/backups", [
        'frequency' => '0 0 * * *',
        'unexpected_field' => 'nope',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('unexpected_field');
});

it('rejects an unexpected field on update_backup', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = databasesExtraFieldsMakeDatabase($team);
    $backup = ScheduledDatabaseBackup::create([
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $team->id,
        'frequency' => '0 0 * * *',
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/databases/{$database->uuid}/backups/{$backup->uuid}", [
        'enabled' => false,
        'unexpected_field' => 'nope',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('unexpected_field');
});
