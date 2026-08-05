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

// Regression coverage for a real, critical credential leak: ProjectController::environment_details()
// (GET /api/v1/projects/{uuid}/{environment_name_or_uuid}) loaded applications/services/every
// database engine onto the Environment response and serialized the whole thing via
// serializeApiResponse() with zero redaction - never wired into RedactsApiSensitiveFields at all,
// unlike every dedicated single-type controller (and unlike ResourcesController's own mixed-type
// /resources endpoint, which solved this exact "multiple resource types in one response" shape
// already). No model in this codebase has a $hidden property (all redaction is opt-in,
// per-controller), so every secret field - docker_compose_raw, manual_webhook_secret_*,
// http_basic_auth_password on Applications; docker_compose_raw on Services; every engine's real
// password columns on databases - came back in plaintext to any token with plain 'read' ability,
// for a whole environment in one call. Same bug class already fixed 4 times this session
// (DatabasesController PR #92, ServersController PR #94/#119, ResourcesController PR #98,
// DeployController PR #99), a fresh instance on a controller none of those PRs touched.
//
// Redis is deliberately excluded from the engine dataset: its password isn't a column on the
// model at all (moved to runtime_environment_variables by an earlier migration), a structurally
// different case this schema-driven test isn't set up to exercise - same exclusion reasoning as
// DatabasesSensitiveFieldsTest.php/ResourcesSensitiveFieldsTest.php.
//
// Separate, disclosed-not-fixed observation surfaced while writing this: environment_details()'s
// own ->load([...]) call never includes keydbs/dragonflies/clickhouses at all (Environment has
// all three relations - keydbs(), dragonflies(), clickhouses() - just never loaded here), so
// those 3 engines' rows never appear in this endpoint's response regardless of redaction. That's
// a completeness gap (an operator using this endpoint doesn't see those resources), not a
// security leak - included in the dataset below anyway since they genuinely don't leak either
// way, just for the unrelated reason of never being in the payload at all. Not fixed here; adding
// the missing relations is a product-completeness change outside a security-redaction fix's scope.

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

function makeApiEnvironmentTeamAndServer(): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();

    return [$team, $user, $project, $environment, $destination];
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

it('never leaks a database engine\'s real password value into the environment-details response', function (string $modelClass, array $secretFields) {
    [$team, $user, $project, $environment, $destination] = makeApiEnvironmentTeamAndServer();
    $modelClass::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        ...$secretFields,
    ]);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/projects/{$project->uuid}/{$environment->uuid}");

    $response->assertOk();
    foreach ($secretFields as $secretValue) {
        expect($response->getContent())->not->toContain($secretValue);
    }
})->with('database engines with real password columns');

it('never leaks an application\'s secrets into the environment-details response', function () {
    [$team, $user, $project, $environment, $destination] = makeApiEnvironmentTeamAndServer();
    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'docker_compose_raw' => 'SECRET-COMPOSE-CONTENTS',
        'manual_webhook_secret_github' => 'SECRET-GITHUB-WEBHOOK',
        'http_basic_auth_password' => 'SECRET-BASIC-AUTH-PW',
    ]);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/projects/{$project->uuid}/{$environment->uuid}");

    $response->assertOk();
    expect($response->getContent())->not->toContain('SECRET-COMPOSE-CONTENTS');
    expect($response->getContent())->not->toContain('SECRET-GITHUB-WEBHOOK');
    expect($response->getContent())->not->toContain('SECRET-BASIC-AUTH-PW');
});

it('never leaks a service\'s secrets into the environment-details response', function () {
    [$team, $user, $project, $environment, $destination] = makeApiEnvironmentTeamAndServer();
    Service::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'docker_compose_raw' => 'SECRET-SERVICE-COMPOSE',
    ]);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/projects/{$project->uuid}/{$environment->uuid}");

    $response->assertOk();
    expect($response->getContent())->not->toContain('SECRET-SERVICE-COMPOSE');
});

it('exposes the real secrets only when the token carries read:sensitive', function () {
    [$team, $user, $project, $environment, $destination] = makeApiEnvironmentTeamAndServer();
    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'docker_compose_raw' => 'SECRET-COMPOSE-CONTENTS',
    ]);
    $token = $this->apiToken($user, $team, ['read', 'read:sensitive']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/projects/{$project->uuid}/{$environment->uuid}");

    $response->assertOk();
    expect($response->getContent())->toContain('SECRET-COMPOSE-CONTENTS');
});
