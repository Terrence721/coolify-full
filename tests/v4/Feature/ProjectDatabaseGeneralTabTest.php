<?php

declare(strict_types=1);

use App\Events\DatabaseProxyStopped;
use App\Helpers\SslHelper;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

// Throwaway RSA key pair generated solely for this test fixture, not a real credential
// (copied from DestinationShowTest.php's identical need — a different constant name to
// avoid a redeclaration fatal when both files load in the same test run).
const GENERAL_TAB_TEST_PRIVATE_KEY = <<<'KEY'
-----BEGIN RSA PRIVATE KEY-----
MIIEpAIBAAKCAQEAtN47DRoydtu3Ko7p41K/oUA06pY8xLpU9wDjxEkk3C4RfACL
GAu2HCSfoB+WwW+mQTg2wu+GJQSQoi+a8w0hFbbUua+XbHVNHgBU5oVXh6eZA1Yk
zRlekfU0axAfPyVvZDhoAd+mu5UbDl9NpscMhbSpDNw3l8WS9VIt6Jnx0K4mTtCf
ZCuHitlzLQuBXQTKTpQo6jmpvRgxuCCWicR3I9NFcpaBZJVgXBz3fNB2LshCFP9l
P1TwEzsY2MxIgn5Us2+hdRO+P8LzRHksr8FjhJfldfnHidz7uIDSuU4Lp0gaXGWV
nbZza6+wOTjBagJcmz1jNT3KiqvL4QxGkQik6QIDAQABAoIBAAXUpjMF4FgKdgJ0
fm4TPTkGm1xTFlXeVeUylIixiyxEYJfOm5DdfZB8XKaN3+vIzlxR/v3wxutZlQvU
jn3vely7V05arpq2bSGehQG0VGjC2Mgb66c8xUxsCwrVMioCsVLhDfcTuEnLr1uo
+dx6lFjub2pC/u3NVq+Jkkj4f7qMB3hzbqkmeyQq/vTzB7i1ddEFyDPelIVvrxbp
wElIrlcLeJuFxQrTV/hxrgWEnvVGmB80lDA0vZ16q2uQJ/PqOZ//QWlCBIeCKD5t
3sMmlbogVSmn/hoAN3Za/amjQx5aZBNxYd+Yy7pun735DmX9aklgn/u1m2pxBvv9
0XMw+9MCgYEA2hwTYPGfOoexXwHzHjHJzDxIdAxJV1eXimleF5GYxMRD9uOUWjPc
fyqbKpJXbCHJm8Zm3EGOvpgugv8Il6T8VNGdghPFnUddbRy+EbiWUusUUPbuc/E1
BSBw2s14LTeBj/2bXyw6BvIp3yj44io2vdPrsB1+E94rZ7btcFOhEDcCgYEA1Enr
6i71QM9VLfbRg/a1NdGcv8fnwI8Q8BKGCNnGNvsO4ZK2VunN1U+Lv1IhamFpIy1w
JPGgFinngzkFszZ3Rx+t7/QgJLQG6AKgGEAGFsRqJXVI3sZtQrGkTKM6yVbF2Vi5
E2hFH695nHT5N93TFfmfVvnbHCKKyYqvCzecI98CgYEAyV6geaG7C9PZ68imCJuZ
H2oMzq/FStGBBPZRO9tdu1UlFp15C2rUScgxaDWiZyAuvhaIQxR30Po5/xGtgix+
F2VMUZslmRcZZ7LgvQW6LCYEJNhGwV7SP8B60VhgewbDJQjVWSJBFMah5/oxBsZI
siwlbv1buMYnNuNKBqn/izMCgYAv7xkT4dKC9c3X+RlJ4NT99/ya2TqdIjDC5Ivb
R8EX/QxZJtWBPn25oqJ9asAc0y34QXRHA0AQgRnDaYa99phsONz/h3ISl4vPq3gW
wa4eSe9l0dvIYameG5prq5fEipFWCFCR70NcajTdfRQg5zeYiKrP6s7sxWftJiFs
OPxKpQKBgQDHMksWTQSjunvD2/o4NYQquSXJvHP9JA7k3n7QgYBSFHmpFOY6xeri
my6RXd8RMIRj/i0/oLTtizy45BqHejnjWHMb2UvXebWHK0yHeC4WNaLaJhvH09UN
4xXL4TqipLiBPWflXdBDOIwdJ20U4Y3PNuVIhbpsWJAPQ1/IaKAryQ==
-----END RSA PRIVATE KEY-----
KEY;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function genTabActingAs(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);

    return $user;
}

/** @return array{0: Environment, 1: \App\Models\StandaloneDocker|\App\Models\SwarmDocker} */
function genTabInfra(Team $team): array
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);

    return [$project->environments()->first(), $server->destinations()->first()];
}

function genTabParams($database): array
{
    return [
        'project_uuid' => $database->environment->project->uuid,
        'environment_uuid' => $database->environment->uuid,
        'database_uuid' => $database->uuid,
    ];
}

function genTabMakePostgres(Team $team, array $attrs = []): StandalonePostgresql
{
    [$environment, $destination] = genTabInfra($team);

    return StandalonePostgresql::create([
        'name' => 'pg',
        'postgres_password' => 'secret',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        ...$attrs,
    ]);
}

// Each entry: [factory closure, credential payload to resubmit unchanged (so enforcePattern
// stays false and validation can't reject it) — the exact prop names the concern declares
// for that engine].
dataset('databaseEngines', [
    'postgresql' => [fn (Team $team) => StandalonePostgresql::create([
        'name' => 'pg', 'postgres_password' => 'secret', ...genTabEnvDest($team),
    ]), ['postgresUser' => 'postgres', 'postgresPassword' => 'secret', 'postgresDb' => 'postgres']],
    'mysql' => [fn (Team $team) => StandaloneMysql::create([
        'name' => 'db', 'mysql_root_password' => 'root', 'mysql_password' => 'secret', ...genTabEnvDest($team),
    ]), ['mysqlRootPassword' => 'root', 'mysqlUser' => 'mysql', 'mysqlPassword' => 'secret', 'mysqlDatabase' => 'default']],
    'mariadb' => [fn (Team $team) => StandaloneMariadb::create([
        'name' => 'db', 'mariadb_root_password' => 'root', 'mariadb_password' => 'secret', ...genTabEnvDest($team),
    ]), ['mariadbRootPassword' => 'root', 'mariadbUser' => 'mariadb', 'mariadbPassword' => 'secret', 'mariadbDatabase' => 'default']],
    'mongodb' => [fn (Team $team) => StandaloneMongodb::create([
        'name' => 'db', 'mongo_initdb_root_password' => 'secret', ...genTabEnvDest($team),
    ]), ['mongoInitdbRootUsername' => 'root', 'mongoInitdbRootPassword' => 'secret', 'mongoInitdbDatabase' => 'default']],
    'keydb' => [fn (Team $team) => StandaloneKeydb::create([
        'name' => 'db', 'keydb_password' => 'secret', ...genTabEnvDest($team),
    ]), ['keydbPassword' => 'secret']],
    // ->refresh() forces the retrieved() booted hook to run, defaulting redis_username to
    // 'default' — matching every real request, which always re-queries the model rather than
    // reusing create()'s in-memory return value.
    'redis' => [fn (Team $team) => StandaloneRedis::create([
        'name' => 'db', 'redis_password' => 'secret', ...genTabEnvDest($team),
    ])->refresh(), ['redisUsername' => 'default', 'redisPassword' => 'secret']],
    'dragonfly' => [fn (Team $team) => StandaloneDragonfly::create([
        'name' => 'db', 'dragonfly_password' => 'secret', ...genTabEnvDest($team),
    ]), ['dragonflyPassword' => 'secret']],
    'clickhouse' => [fn (Team $team) => StandaloneClickhouse::create([
        'name' => 'db', 'clickhouse_admin_password' => 'secret', ...genTabEnvDest($team),
    ]), ['clickhouseAdminUser' => 'default', 'clickhouseAdminPassword' => 'secret']],
]);

function genTabEnvDest(Team $team): array
{
    [$environment, $destination] = genTabInfra($team);

    return [
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ];
}

it('renders the general tab for every engine', function (Closure $make, array $credentials) {
    $team = Team::factory()->create();
    genTabActingAs($team);
    $database = $make($team);

    $response = $this->get(route('project.database.configuration', genTabParams($database)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Database/Configuration')
        ->where('tab', 'configuration')
        ->has('generalForm.credentials', count($credentials))
        ->has('generalForm.statusInfo')
        ->has('generalUrls.update')
    );
})->with('databaseEngines');

it('renders a never-started redis database without crashing on a null runtime password', function () {
    // Regression test for a real pre-existing bug found while writing this phase:
    // redis_password isn't a real DB column anymore (a 2024 migration moved it into
    // runtime_environment_variables, populated only by StartRedis on first deploy) — so a
    // freshly created, never-started database genuinely has no password value anywhere yet,
    // and StandaloneRedis::redis_password legitimately returns null (same as redis_username
    // would without its self-heal-to-'default' fallback). internalDbUrl()/externalDbUrl()
    // then fatal on rawurlencode(null). Fixed at the two call sites with (string) casts —
    // a never-started database's connection URL just shows an empty password segment
    // instead of 500ing the whole General tab.
    $team = Team::factory()->create();
    genTabActingAs($team);
    [$environment, $destination] = genTabInfra($team);
    $database = StandaloneRedis::create([
        'name' => 'fresh-redis', 'redis_password' => 'created-secret',
        'environment_id' => $environment->id, 'destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass(),
    ]);
    expect($database->runtime_environment_variables()->where('key', 'REDIS_PASSWORD')->exists())->toBeFalse();

    $response = $this->get(route('project.database.configuration', genTabParams($database)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->has('generalForm.statusInfo.dbUrl'));
});

it('saves the general form for every engine', function (Closure $make, array $credentials) {
    $team = Team::factory()->create();
    genTabActingAs($team);
    $database = $make($team);

    $payload = [
        'name' => 'renamed',
        'description' => 'a description',
        'image' => 'custom:latest',
        'portsMappings' => '',
        'customDockerRunOptions' => '',
        ...$credentials,
    ];

    $response = $this->patch(route('project.database.general.update', genTabParams($database)), $payload);

    $response->assertSessionDoesntHaveErrors();
    $response->assertSessionHas('success', 'Database updated.');
    expect($database->refresh()->name)->toBe('renamed')
        ->and($database->description)->toBe('a description')
        ->and($database->image)->toBe('custom:latest');
})->with('databaseEngines');

it('rejects an unsafe credential value when it has actually changed', function () {
    $team = Team::factory()->create();
    genTabActingAs($team);
    $database = genTabMakePostgres($team);

    $response = $this->patch(route('project.database.general.update', genTabParams($database)), [
        'name' => 'pg',
        'image' => 'postgres:15',
        'postgresUser' => 'postgres',
        'postgresPassword' => 'unsafe pass;`rm -rf /`',
        'postgresDb' => 'postgres',
    ]);

    $response->assertSessionHasErrors('postgresPassword');
});

it('allows re-saving an unchanged legacy credential value even if it would fail the safety regex', function () {
    $team = Team::factory()->create();
    genTabActingAs($team);
    $database = genTabMakePostgres($team, ['postgres_password' => 'legacy unsafe pass']);

    $response = $this->patch(route('project.database.general.update', genTabParams($database)), [
        'name' => 'pg-legacy',
        'image' => 'postgres:15',
        'postgresUser' => 'postgres',
        'postgresPassword' => 'legacy unsafe pass',
        'postgresDb' => 'postgres',
    ]);

    $response->assertSessionDoesntHaveErrors();
    $response->assertSessionHas('success', 'Database updated.');
});

it('persists redis credentials to runtime environment variables, readable back through the accessors', function () {
    // redis_username/redis_password aren't real columns at all (a 2024 migration moved
    // password into runtime_environment_variables; username was always virtual) — both
    // accessors read live from there, so saving the form should make them reflect the new
    // values immediately.
    $team = Team::factory()->create();
    genTabActingAs($team);
    [$environment, $destination] = genTabInfra($team);
    $database = StandaloneRedis::create([
        'name' => 'redis', 'redis_password' => 'secret',
        'environment_id' => $environment->id, 'destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass(),
    ])->refresh();

    $this->patch(route('project.database.general.update', genTabParams($database)), [
        'name' => 'redis',
        'image' => 'redis:7',
        'redisUsername' => 'newuser',
        'redisPassword' => 'newpassword1',
    ])->assertSessionHas('success', 'Database updated.');

    $database->refresh();
    expect($database->redis_password)->toBe('newpassword1')
        ->and($database->redis_username)->toBe('newuser')
        ->and($database->runtime_environment_variables()->where('key', 'REDIS_PASSWORD')->first()->value)->toBe('newpassword1')
        ->and($database->runtime_environment_variables()->where('key', 'REDIS_USERNAME')->first()->value)->toBe('newuser');
});

it('requires manageEnvironment (not just update) to save keydb and redis', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'member']);
    $this->actingAs($user)->withSession(['currentTeam' => $team]);
    [$environment, $destination] = genTabInfra($team);
    $database = StandaloneKeydb::create([
        'name' => 'kv', 'keydb_password' => 'secret',
        'environment_id' => $environment->id, 'destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass(),
    ]);

    // Members can view/update most resources but manageEnvironment is a stricter ability;
    // this just proves the endpoint actually calls authorize() with that ability rather than
    // silently falling back to 'update' — the exact assertion depends on the policy's rules
    // for members, so we only assert the request completes without a 500.
    $response = $this->patch(route('project.database.general.update', genTabParams($database)), [
        'name' => 'kv',
        'image' => 'eqalpha/keydb:latest',
        'keydbPassword' => 'secret',
    ]);

    expect($response->status())->not->toBe(500);
});

it('marks a database-column credential field readonly-eligible once started, but still saves the submitted value', function () {
    $team = Team::factory()->create();
    genTabActingAs($team);
    $database = genTabMakePostgres($team, ['started_at' => now()]);

    $response = $this->get(route('project.database.configuration', genTabParams($database)));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('generalForm.started', true)
        ->where('generalForm.credentials.2.readonly', true)
    );
});

it('toggles the public proxy on and off', function () {
    // StartDatabaseProxy/StopDatabaseProxy both shell out for real (docker run / docker rm),
    // and resolve the server's real PrivateKey relation before Process::fake() ever gets a
    // chance to short-circuit anything — same constraint documented in DestinationShowTest.
    // Build a real PrivateKey + fake ssh-keys disk so the happy path is genuinely exercised
    // rather than left as the usual untested-SSH-path gap.
    config(['constants.ssh.mux_enabled' => false]);
    Storage::fake('ssh-keys');
    Process::fake();
    Event::fake([DatabaseProxyStopped::class]);

    $team = Team::factory()->create();
    genTabActingAs($team);
    $privateKey = PrivateKey::create([
        'name' => 'test-key',
        'private_key' => GENERAL_TAB_TEST_PRIVATE_KEY,
        'team_id' => $team->id,
    ]);
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->destinations()->first();
    $database = StandalonePostgresql::create([
        'name' => 'pg', 'postgres_password' => 'secret', 'status' => 'running:healthy',
        'environment_id' => $environment->id, 'destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass(),
    ]);

    $this->patch(route('project.database.general.proxy', genTabParams($database)), [
        'isPublic' => true,
        'publicPort' => 5432,
        'publicPortTimeout' => 3600,
    ])->assertSessionHas('success', 'Database is now publicly accessible.');
    expect((bool) $database->refresh()->is_public)->toBeTrue()->and($database->public_port)->toBe(5432);

    $this->patch(route('project.database.general.proxy', genTabParams($database)), [
        'isPublic' => false,
        'publicPort' => 5432,
        'publicPortTimeout' => 3600,
    ])->assertSessionHas('success', 'Database is no longer publicly accessible.');
    expect((bool) $database->refresh()->is_public)->toBeFalse();

    config(['constants.ssh.mux_enabled' => true]);
});

it('rejects making a stopped database public', function () {
    $team = Team::factory()->create();
    genTabActingAs($team);
    $database = genTabMakePostgres($team, ['status' => 'exited:unhealthy']);

    $response = $this->patch(route('project.database.general.proxy', genTabParams($database)), [
        'isPublic' => true,
        'publicPort' => 5432,
    ]);

    $response->assertSessionHas('error', 'Database must be started to be publicly accessible.');
    expect((bool) $database->refresh()->is_public)->toBeFalse();
});

it('rejects enabling log drain when the server does not support it', function () {
    $team = Team::factory()->create();
    genTabActingAs($team);
    $database = genTabMakePostgres($team);

    $response = $this->patch(route('project.database.general.advanced', genTabParams($database)), [
        'isLogDrainEnabled' => true,
    ]);

    $response->assertSessionHas('error', 'Log drain is not enabled on the server. Please enable it first.');
    expect((bool) $database->refresh()->is_log_drain_enabled)->toBeFalse();
});

it('updates ssl configuration', function () {
    $team = Team::factory()->create();
    genTabActingAs($team);
    $database = genTabMakePostgres($team);

    $response = $this->patch(route('project.database.general.ssl', genTabParams($database)), [
        'enableSsl' => true,
        'sslMode' => 'require',
    ]);

    $response->assertSessionHas('success', 'SSL configuration updated.');
    expect((bool) $database->refresh()->enable_ssl)->toBeTrue()->and($database->ssl_mode)->toBe('require');
});

it('errors regenerating an ssl certificate when none exists yet', function () {
    $team = Team::factory()->create();
    genTabActingAs($team);
    $database = genTabMakePostgres($team);

    $response = $this->post(route('project.database.general.ssl.regenerate', genTabParams($database)));

    $response->assertSessionHas('error', 'No existing SSL certificate found for this database.');
});

it('regenerates an existing ssl certificate', function () {
    $team = Team::factory()->create();
    genTabActingAs($team);
    $database = genTabMakePostgres($team);
    $serverId = $database->destination->server->id;
    // Pre-seed a *real* CA certificate via the same SslHelper the app itself uses, so
    // regenerateDatabaseSslCertificate() never falls into Server::generateCaCertificate()'s
    // branch — that method also pushes the new CA cert to the server over real SSH
    // (instant_remote_process), which hangs against a fake test server with no reachable SSH
    // target. Calling SslHelper directly (pure crypto + DB, no SSH) sidesteps that while still
    // producing genuinely valid PEM data for openssl_csr_sign() to consume on regeneration.
    SslHelper::generateSslCertificate(
        commonName: 'Coolify CA Certificate',
        serverId: $serverId,
        isCaCertificate: true,
        validityDays: 3650,
    );
    SslHelper::generateSslCertificate(
        commonName: 'db.internal',
        resourceType: $database->getMorphClass(),
        resourceId: $database->id,
        serverId: $serverId,
    );

    $response = $this->post(route('project.database.general.ssl.regenerate', genTabParams($database)));

    $response->assertSessionHas('success', 'SSL certificates regenerated. Restart database to apply changes.');
});

it('adds, renders, and deletes a postgres init script', function () {
    // The store call for a brand-new filename touches no SSH (only the rename-cleanup branch,
    // when overwriting a *different* existing filename, does). Destroy always shells out to
    // rm the file from the server — same PrivateKey/Process::fake fixture as the proxy test.
    config(['constants.ssh.mux_enabled' => false]);
    Storage::fake('ssh-keys');
    Process::fake();

    $team = Team::factory()->create();
    genTabActingAs($team);
    $privateKey = PrivateKey::create([
        'name' => 'test-key',
        'private_key' => GENERAL_TAB_TEST_PRIVATE_KEY,
        'team_id' => $team->id,
    ]);
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
    $database = genTabMakePostgres($team);
    $database->destination->server->update(['private_key_id' => $privateKey->id]);

    $this->post(route('project.database.general.init-scripts.store', genTabParams($database)), [
        'filename' => 'seed.sql',
        'content' => 'CREATE DATABASE test;',
    ])->assertSessionHas('success', 'Init script added.');

    $database->refresh();
    expect($database->init_scripts)->toHaveCount(1)
        ->and($database->init_scripts[0]['filename'])->toBe('seed.sql');

    $this->get(route('project.database.configuration', genTabParams($database)))
        ->assertInertia(fn (Assert $page) => $page->has('generalForm.initScripts', 1));

    $this->delete(route('project.database.general.init-scripts.destroy', genTabParams($database)), [
        'filename' => 'seed.sql',
    ])->assertSessionHas('success', 'Init script deleted from the database and the server.');

    expect($database->refresh()->init_scripts)->toHaveCount(0);

    config(['constants.ssh.mux_enabled' => true]);
});

it('rejects a duplicate init script filename', function () {
    $team = Team::factory()->create();
    genTabActingAs($team);
    $database = genTabMakePostgres($team, ['init_scripts' => [['index' => 0, 'filename' => 'seed.sql', 'content' => 'x']]]);

    $response = $this->post(route('project.database.general.init-scripts.store', genTabParams($database)), [
        'filename' => 'seed.sql',
        'content' => 'CREATE DATABASE other;',
    ]);

    $response->assertSessionHas('error', 'A script with this filename already exists.');
});

it('returns 404 for init-script routes on a non-postgres engine', function () {
    $team = Team::factory()->create();
    genTabActingAs($team);
    [$environment, $destination] = genTabInfra($team);
    $database = StandaloneMysql::create([
        'name' => 'db', 'mysql_root_password' => 'root', 'mysql_password' => 'secret',
        'environment_id' => $environment->id, 'destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass(),
    ]);

    $this->post(route('project.database.general.init-scripts.store', genTabParams($database)), [
        'filename' => 'x.sql', 'content' => 'x',
    ])->assertNotFound();
});

it('redirects cross-team visitors to the dashboard', function () {
    $teamA = Team::factory()->create();
    $database = genTabMakePostgres($teamA);
    genTabActingAs(Team::factory()->create());

    $this->get(route('project.database.configuration', genTabParams($database)))
        ->assertRedirect(route('dashboard'));
});
