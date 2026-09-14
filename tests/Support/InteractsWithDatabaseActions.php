<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\SslCertificate;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDatabaseInstance;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Tests\Support\Fakes\DatabaseActionFake;
use Tests\Support\Fakes\RemoteProcessFake;

require_once __DIR__.'/Fakes/database_action_overrides.php';

/**
 * Shared fixture builders for the App\Actions\Database Start* action tests
 * (StartDragonfly, StartKeydb, StartMariadb, ...). These actions all follow the same
 * shape: a `destination` relation exposing `network`/`server`, and an SSL branch that
 * looks up a CA certificate on the server before generating (or reusing) the database's
 * own certificate.
 */
trait InteractsWithDatabaseActions
{
    use CallsProtectedMethods;

    /**
     * The action under test, e.g. StartMysql for StartMysqlTest. Untyped (rather than
     * redeclared per test class) so this trait can build it automatically in setUp() —
     * see the class-name convention there.
     */
    protected object $action;

    /**
     * Resets the shared remote_process/action call-recording fakes and instantiates
     * $this->action before each test, so consuming test classes don't need their own
     * setUp() at all. Relies on a strict naming convention: {ActionClass}Test ->
     * App\Actions\Database\{ActionClass} (e.g. StartMysqlTest -> StartMysql).
     */
    protected function setUp(): void
    {
        parent::setUp();

        DatabaseActionFake::reset();
        RemoteProcessFake::reset();

        $actionClass = 'App\\Actions\\Database\\Start'.$this->engineName();
        $this->action = new $actionClass;
    }

    /**
     * Extracts the engine name from the test class, e.g. "Mysql" for StartMysqlTest.
     * Relies on the {ActionClass}Test naming convention, where {ActionClass} is
     * Start{Engine} (e.g. StartMysqlTest -> "Mysql").
     */
    private function engineName(): string
    {
        $testClassName = (new \ReflectionClass($this))->getShortName();

        return substr($testClassName, strlen('Start'), -strlen('Test'));
    }

    private const TEST_PRIVATE_KEY = <<<'KEY'
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

    private ?Team $testTeam = null;

    private ?PrivateKey $testPrivateKey = null;

    /**
     * Auto-incrementing suffix keeps uuid/ip unique across every test using this trait in the
     * run. team_id/private_key_id used to be hardcoded to the literal 1 - silently accepted
     * with no FK enforcement on the old testing-schema.sql, but neither row actually existed,
     * so it broke loudly the moment FK constraints were added (issue #77). Creates one real
     * Team/PrivateKey per test instead (cached on the test instance, reused across multiple
     * createTestServer() calls within the same test), and lets $overrides still win if a test
     * explicitly wants a different team/key.
     */
    private function createTestServer(array $overrides = []): Server
    {
        static $count = 0;
        $count++;

        $this->testTeam ??= Team::factory()->create();
        $this->testPrivateKey ??= PrivateKey::create([
            'name' => 'test-key',
            'private_key' => self::TEST_PRIVATE_KEY,
            'team_id' => $this->testTeam->id,
        ]);

        return Server::create(array_merge([
            'name' => "srv-test-{$count}",
            'uuid' => "srv-test-{$count}-uuid",
            'ip' => '127.0.1.'.($count % 254 + 1),
            'team_id' => $this->testTeam->id,
            'private_key_id' => $this->testPrivateKey->id,
        ], $overrides));
    }

    /**
     * Pre-seeds a CA certificate so the SSL branch finds an existing one instead of
     * calling Server::generateCaCertificate() -> SslHelper::generateSslCertificate(),
     * which relies on openssl_pkey_new() — unavailable in this environment.
     */
    private function seedCaCertificate(Server $server): SslCertificate
    {
        return SslCertificate::create([
            'ssl_certificate' => 'ca-cert',
            'ssl_private_key' => 'ca-key',
            'server_id' => $server->id,
            'common_name' => 'Coolify CA Certificate',
            'valid_until' => now()->addYears(10),
            'is_ca_certificate' => true,
        ]);
    }

    /** Pre-seeds the database's own certificate to avoid SslHelper::generateSslCertificate(). */
    private function seedResourceCertificate(Server $server, string $resourceType, int $resourceId, string $commonName): SslCertificate
    {
        return SslCertificate::create([
            'ssl_certificate' => 'db-cert',
            'ssl_private_key' => 'db-key',
            'server_id' => $server->id,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'common_name' => $commonName,
            'valid_until' => now()->addYear(),
            'is_ca_certificate' => false,
        ]);
    }

    /**
     * Seeds a real runtime_environment_variables row on $database. Needed for engines
     * (e.g. Redis's redis_password) whose credential is a computed accessor reading this
     * relation via a live query — setRelation()/create() overrides on the model itself
     * don't reach it.
     */
    private function seedEnvironmentVariable(Model $database, string $key, string $value, bool $isShared = false): void
    {
        $database->runtime_environment_variables()->create([
            'key' => $key,
            'value' => $value,
            'is_shared' => $isShared,
        ]);
    }

    /** destination.server as an object — sslCertificates()/generateCaCertificate() are called through it. */
    private function destinationWithServer(Server $server, string $network = 'net-1'): object
    {
        return new class($server, $network)
        {
            public function __construct(public Server $server, public string $network) {}
        };
    }

    /** destination without a server yet — used for the non-SSL fixture default. */
    private function destinationWithoutServer(string $network = 'net-1'): object
    {
        return new class($network)
        {
            public ?Server $server = null;

            public function __construct(public string $network) {}
        };
    }

    /**
     * Builds a persisted Standalone* row with the fixture defaults every Start*Database
     * test needs (destination type/id, resource limits, no SSL, empty relations),
     * merged with the caller's engine-specific overrides (credentials, uuid, image, ...).
     *
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function fakeStandaloneDatabase(string $class, array $overrides = []): Model
    {
        $db = $class::create(array_merge([
            'enable_ssl' => false,
            'destination_type' => StandaloneDocker::class,
            'destination_id' => 1,
            'limits_cpus' => 0.5,
            'limits_cpu_shares' => 1024,
            'custom_docker_run_options' => '',
        ], $overrides));

        $db->setRelation('destination', $this->destinationWithoutServer());
        $db->setRelation('persistentStorages', collect());
        $db->setRelation('runtime_environment_variables', collect());

        return $db;
    }

    /** @return array<string, array<string, mixed>> Standalone{Engine} class => that engine's default fixture fields */
    private function engineFixtureDefaults(): array
    {
        return [
            StandalonePostgresql::class => [
                'uuid' => 'pg-123',
                'name' => 'postgresql',
                'image' => 'postgres:16-alpine',
                'postgres_user' => 'pguser',
                'postgres_password' => 'pgpass',
                'postgres_db' => 'appdb',
                'postgres_conf' => null,
            ],
            StandaloneMysql::class => [
                'uuid' => 'mysql-123',
                'name' => 'mysql',
                'image' => 'mysql:latest',
                'mysql_root_password' => 'rootpw',
                'mysql_database' => 'appdb',
                'mysql_user' => 'appuser',
                'mysql_password' => 'apppw',
                'mysql_conf' => null,
            ],
            StandaloneMariadb::class => [
                'uuid' => 'maria-123',
                'name' => 'mariadb',
                'image' => 'mariadb:latest',
                'mariadb_root_password' => 'rootpw',
                'mariadb_database' => 'appdb',
                'mariadb_user' => 'appuser',
                'mariadb_password' => 'apppw',
                'mariadb_conf' => null,
            ],
            StandaloneMongodb::class => [
                'uuid' => 'mongo-123',
                'name' => 'mongodb',
                'image' => 'mongo:6.0',
                'mongo_initdb_root_username' => 'root',
                'mongo_initdb_root_password' => 'rootpw',
                'mongo_initdb_database' => 'appdb',
                'mongo_conf' => null,
                'ssl_mode' => 'require',
            ],
            StandaloneRedis::class => [
                'uuid' => 'redis-123',
                'name' => 'redis',
                'image' => 'redis:latest',
                // redis_password is NOT a real column (see the move_redis_password_to_envs
                // migration) — it's a computed accessor reading a REDIS_PASSWORD row from
                // runtime_environment_variables via a live query, so it can't be seeded
                // through create() like the other engines' *_password fields. Tests that
                // need a real value must seed that row explicitly.
                'redis_conf' => null,
            ],
            StandaloneKeydb::class => [
                'uuid' => 'kb-123',
                'name' => 'keydb',
                'image' => 'keydb:latest',
                'keydb_password' => 's3cr3t',
                'keydb_conf' => null,
            ],
            StandaloneDragonfly::class => [
                'uuid' => 'df-123',
                'name' => 'dragon',
                'image' => 'dragonfly:latest',
                'dragonfly_password' => 's3cr3t',
            ],
            StandaloneClickhouse::class => [
                'uuid' => 'clickhouse-123',
                'name' => 'clickhouse',
                'image' => 'clickhouse:latest',
                'clickhouse_admin_user' => 'admin',
                'clickhouse_admin_password' => 'secret',
                'clickhouse_db' => 'default',
            ],
        ];
    }

    /**
     * Builds a persisted Standalone* row for the engine matching this test class's
     * naming convention (Start{Engine}Test -> App\Models\Standalone{Engine}), merged
     * with that engine's own fixture defaults and the caller's overrides.
     *
     * sslCertificates() and fileStorages() are called as chained relation methods on
     * these actions, not accessed as cached relation properties — so setRelation()
     * can't intercept them. A real, persisted model (with RefreshDatabase providing
     * the real, empty tables) is required instead.
     */
    private function fakeDatabase(array $overrides = []): StandaloneDatabaseInstance
    {
        $modelClass = 'App\\Models\\Standalone'.$this->engineName();

        return $this->fakeStandaloneDatabase($modelClass, array_merge(
            $this->engineFixtureDefaults()[$modelClass] ?? [],
            $overrides
        ));
    }
}
