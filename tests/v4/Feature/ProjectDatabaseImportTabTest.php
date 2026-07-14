<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function importTabActingAs(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);

    return $user;
}

/** @return array{0: \App\Models\Environment, 1: \App\Models\StandaloneDocker|\App\Models\SwarmDocker, 2: Server} */
function importTabInfra(Team $team): array
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);

    return [$project->environments()->first(), $server->destinations()->first(), $server];
}

function importTabMakePostgres(Team $team, string $status = 'running:healthy'): StandalonePostgresql
{
    [$environment, $destination] = importTabInfra($team);

    return StandalonePostgresql::create([
        'name' => 'pg',
        'postgres_password' => 'secret',
        'status' => $status,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function importTabParams(StandalonePostgresql $database): array
{
    return [
        'project_uuid' => $database->environment->project->uuid,
        'environment_uuid' => $database->environment->uuid,
        'database_uuid' => $database->uuid,
    ];
}

it('renders the import tab for a running postgres', function () {
    $team = Team::factory()->create();
    importTabActingAs($team);
    $database = importTabMakePostgres($team);

    $response = $this->get(route('project.database.import-backup', importTabParams($database)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Database/Configuration')
        ->where('tab', 'import-backup')
        ->where('importTab.unsupported', false)
        ->where('importTab.running', true)
        ->where('importTab.dbType', 'standalone-postgresql')
        ->where('importTab.commands.default', 'pg_restore -U $POSTGRES_USER -d ${POSTGRES_DB:-${POSTGRES_USER:-postgres}}')
        ->has('importTab.urls.run')
    );
});

it('marks unsupported engines and stopped databases', function () {
    $team = Team::factory()->create();
    importTabActingAs($team);
    [$environment, $destination] = importTabInfra($team);
    $redis = StandaloneRedis::create([
        'name' => 'redis',
        'redis_password' => 'secret',
        'status' => 'running:healthy',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $this->get(route('project.database.import-backup', [
        'project_uuid' => $environment->project->uuid,
        'environment_uuid' => $environment->uuid,
        'database_uuid' => $redis->uuid,
    ]))->assertInertia(fn (Assert $page) => $page->where('importTab.unsupported', true));

    $stopped = importTabMakePostgres(Team::factory()->create(), 'exited:unhealthy');
    importTabActingAs($stopped->team());
    $this->get(route('project.database.import-backup', importTabParams($stopped)))
        ->assertInertia(fn (Assert $page) => $page->where('importTab.running', false));
});

it('renders the import tab for a service database', function () {
    $team = Team::factory()->create();
    importTabActingAs($team);
    [$environment, $destination, $server] = importTabInfra($team);
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $serviceDatabase = ServiceDatabase::create([
        'name' => 'mysql',
        'image' => 'mysql:8',
        'status' => 'running:healthy',
        'service_id' => $service->id,
    ]);

    $response = $this->get(route('project.service.database.import', [
        'project_uuid' => $environment->project->uuid,
        'environment_uuid' => $environment->uuid,
        'service_uuid' => $service->uuid,
        'stack_service_uuid' => $serviceDatabase->uuid,
    ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Service/Resource')
        ->where('tab', 'import')
        ->where('resourceType', 'database')
        ->where('importTab.dbType', 'standalone-mysql')
        ->where('importTab.running', true)
    );
});

it('rejects an unsafe custom location on check-file', function () {
    $team = Team::factory()->create();
    importTabActingAs($team);
    $database = importTabMakePostgres($team);

    $response = $this->post(route('project.database.import.check-file', importTabParams($database)), [
        'customLocation' => '/tmp/backup.sql; rm -rf /',
    ]);

    $response->assertSessionHas('error', 'Invalid file path. Path must be absolute and contain only safe characters (alphanumerics, dots, dashes, underscores, slashes).');
});

it('rejects a wrong password on run', function () {
    $team = Team::factory()->create();
    importTabActingAs($team);
    $database = importTabMakePostgres($team);

    $response = $this->post(route('project.database.import.run', importTabParams($database)), [
        'password' => 'wrong-password',
        'restoreCommand' => 'pg_restore -U $POSTGRES_USER',
    ]);

    $response->assertSessionHas('error', 'The provided password is incorrect.');
});

it('errors when no backup file was provided on run', function () {
    $team = Team::factory()->create();
    importTabActingAs($team);
    $database = importTabMakePostgres($team);

    $response = $this->post(route('project.database.import.run', importTabParams($database)), [
        'password' => 'password',
        'restoreCommand' => 'pg_restore -U $POSTGRES_USER',
    ]);

    $response->assertSessionHas('error', 'The file does not exist or has been deleted.');
});

it('rejects an unsafe s3 path on check', function () {
    $team = Team::factory()->create();
    importTabActingAs($team);
    $database = importTabMakePostgres($team);

    $response = $this->post(route('project.database.import.check-s3', importTabParams($database)), [
        's3StorageId' => 1,
        's3Path' => '/backups/../secrets.gz',
    ]);

    $response->assertSessionHas('error', 'Invalid S3 path. Path must contain only safe characters (alphanumerics, dots, dashes, underscores, slashes).');
});

it('rejects a wrong password on s3 restore', function () {
    $team = Team::factory()->create();
    importTabActingAs($team);
    $database = importTabMakePostgres($team);

    $response = $this->post(route('project.database.import.restore-s3', importTabParams($database)), [
        'password' => 'wrong-password',
        's3StorageId' => 1,
        's3Path' => '/backups/db.gz',
        'restoreCommand' => 'pg_restore -U $POSTGRES_USER',
    ]);

    $response->assertSessionHas('error', 'The provided password is incorrect.');
});

it('redirects cross-team visitors to the dashboard', function () {
    $teamA = Team::factory()->create();
    $database = importTabMakePostgres($teamA);
    importTabActingAs(Team::factory()->create());

    $this->get(route('project.database.import-backup', importTabParams($database)))
        ->assertRedirect(route('dashboard'));
});
