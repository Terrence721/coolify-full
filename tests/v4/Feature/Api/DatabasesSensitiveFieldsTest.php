<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithApiV1;

// Regression coverage for a real credential leak: removeSensitiveData() redacted the password
// field for 6 of 8 database engines but silently omitted MySQL and MariaDB - mysql_password,
// mysql_root_password, mariadb_password, and mariadb_root_password weren't in either the
// always-hidden or read:sensitive-gated list, so any token with plain 'read' ability got them
// back in plaintext. Confirmed empirically before filing: a real StandaloneMysql row's
// mysql_root_password came back verbatim in a real /api/v1/databases/{uuid} response.
//
// Rather than just re-testing the two fixed fields, this covers every engine with a real secret
// value in its actual password column(s) end to end - the point isn't "is this field name in a
// hand-maintained list", it's "does a real secret ever actually appear in a real response",
// which is what would have caught this bug regardless of which engine's field got forgotten.
// Redis is deliberately excluded: its password isn't a column on the model at all (moved to
// runtime_environment_variables by migration 2024_10_16_120026), a structurally different case
// this schema-driven test isn't set up to exercise.

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

/**
 * @return array{Team, User, StandalonePostgresql|StandaloneMysql|StandaloneMariadb|StandaloneMongodb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse}
 */
function makeApiDatabaseWithSecrets(string $modelClass, array $secretFields): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();

    $database = $modelClass::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        ...$secretFields,
    ]);

    return [$team, $user, $database];
}

dataset('database engines with real password columns', [
    'postgresql' => [StandalonePostgresql::class, ['postgres_password' => 'SECRET-POSTGRES-PW']],
    'mysql' => [StandaloneMysql::class, ['mysql_password' => 'SECRET-MYSQL-USER-PW', 'mysql_root_password' => 'SECRET-MYSQL-ROOT-PW']],
    'mariadb' => [StandaloneMariadb::class, ['mariadb_password' => 'SECRET-MARIADB-USER-PW', 'mariadb_root_password' => 'SECRET-MARIADB-ROOT-PW']],
    'mongodb' => [StandaloneMongodb::class, ['mongo_initdb_root_password' => 'SECRET-MONGO-PW']],
    'keydb' => [StandaloneKeydb::class, ['keydb_password' => 'SECRET-KEYDB-PW']],
    'dragonfly' => [StandaloneDragonfly::class, ['dragonfly_password' => 'SECRET-DRAGONFLY-PW']],
    'clickhouse' => [StandaloneClickhouse::class, ['clickhouse_admin_password' => 'SECRET-CLICKHOUSE-PW']],
]);

it('never leaks a real password value into the single-resource API response, for any engine', function (string $modelClass, array $secretFields) {
    [$team, $user, $database] = makeApiDatabaseWithSecrets($modelClass, $secretFields);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/databases/{$database->uuid}");

    $response->assertOk();
    foreach ($secretFields as $secretValue) {
        expect($response->getContent())->not->toContain($secretValue);
    }
})->with('database engines with real password columns');

it('never leaks a real password value into the databases-list API response, for any engine', function (string $modelClass, array $secretFields) {
    [$team, $user] = makeApiDatabaseWithSecrets($modelClass, $secretFields);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/databases');

    $response->assertOk();
    foreach ($secretFields as $secretValue) {
        expect($response->getContent())->not->toContain($secretValue);
    }
})->with('database engines with real password columns');

it('exposes the real password only when the token carries read:sensitive', function () {
    [$team, $user, $database] = makeApiDatabaseWithSecrets(StandaloneMysql::class, [
        'mysql_password' => 'SECRET-MYSQL-USER-PW',
        'mysql_root_password' => 'SECRET-MYSQL-ROOT-PW',
    ]);
    $token = $this->apiToken($user, $team, ['read', 'read:sensitive']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/databases/{$database->uuid}");

    $response->assertOk();
    $response->assertJsonFragment(['mysql_password' => 'SECRET-MYSQL-USER-PW']);
    $response->assertJsonFragment(['mysql_root_password' => 'SECRET-MYSQL-ROOT-PW']);
});
