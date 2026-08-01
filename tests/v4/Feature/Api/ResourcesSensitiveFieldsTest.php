<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
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

// Regression coverage for a real, critical credential leak: ResourcesController::resources()
// (GET /api/v1/resources) called raw $resource->toArray() on every Application/Service/database
// row - no redaction at all, unlike every other controller returning these same model types. No
// model in this codebase has a $hidden property (all redaction is opt-in, per-controller), so
// every secret field (docker_compose_raw, manual_webhook_secret_*, http_basic_auth_password on
// Applications; docker_compose_raw on Services; every engine's real password columns on
// databases) came back in plaintext to any token with plain 'read' ability, for every resource
// on the team, in one call - a broader version of the exact bug class already fixed twice
// (DatabasesController MySQL/MariaDB passwords PR #92, ServersController log-drain keys PR #94).
//
// Redis is deliberately excluded from the engine dataset: its password isn't a column on the
// model at all (moved to runtime_environment_variables by an earlier migration), a structurally
// different case this schema-driven test isn't set up to exercise - same exclusion reasoning as
// DatabasesSensitiveFieldsTest.php.

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

function makeApiResourcesTeamAndServer(): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();

    return [$team, $user, $environment, $destination];
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

it('never leaks a database engine\'s real password value into the resources-list response', function (string $modelClass, array $secretFields) {
    [$team, $user, $environment, $destination] = makeApiResourcesTeamAndServer();
    $modelClass::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        ...$secretFields,
    ]);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/resources');

    $response->assertOk();
    foreach ($secretFields as $secretValue) {
        expect($response->getContent())->not->toContain($secretValue);
    }
})->with('database engines with real password columns');

it('never leaks an application\'s secrets into the resources-list response', function () {
    [$team, $user, $environment, $destination] = makeApiResourcesTeamAndServer();
    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'docker_compose_raw' => 'SECRET-COMPOSE-CONTENTS',
        'manual_webhook_secret_github' => 'SECRET-GITHUB-WEBHOOK',
        'http_basic_auth_password' => 'SECRET-BASIC-AUTH-PW',
    ]);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/resources');

    $response->assertOk();
    expect($response->getContent())->not->toContain('SECRET-COMPOSE-CONTENTS');
    expect($response->getContent())->not->toContain('SECRET-GITHUB-WEBHOOK');
    expect($response->getContent())->not->toContain('SECRET-BASIC-AUTH-PW');
});

it('never leaks a service\'s secrets into the resources-list response', function () {
    [$team, $user, $environment, $destination] = makeApiResourcesTeamAndServer();
    Service::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'docker_compose_raw' => 'SECRET-SERVICE-COMPOSE',
    ]);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/resources');

    $response->assertOk();
    expect($response->getContent())->not->toContain('SECRET-SERVICE-COMPOSE');
});

it('still returns status and type for every resource once redacted', function () {
    [$team, $user, $environment, $destination] = makeApiResourcesTeamAndServer();
    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'name' => 'my-app',
    ]);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/resources');

    $response->assertOk();
    $response->assertJsonFragment(['name' => 'my-app', 'type' => 'application']);
});

it('exposes real secret values only when the token carries read:sensitive', function () {
    [$team, $user, $environment, $destination] = makeApiResourcesTeamAndServer();
    StandaloneMysql::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'mysql_root_password' => 'SECRET-MYSQL-ROOT-PW',
    ]);
    $token = $this->apiToken($user, $team, ['read', 'read:sensitive']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/resources');

    $response->assertOk();
    $response->assertJsonFragment(['mysql_root_password' => 'SECRET-MYSQL-ROOT-PW']);
});
