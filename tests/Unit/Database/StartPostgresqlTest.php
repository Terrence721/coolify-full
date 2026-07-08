<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Database;

use App\Models\SslCertificate;
use App\Models\StandalonePostgresql;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Fakes\DatabaseActionFake;
use Tests\Support\InteractsWithDatabaseActions;
use Tests\TestCase;

final class StartPostgresqlTest extends TestCase
{
    use InteractsWithDatabaseActions;
    use RefreshDatabase;

    #[Test]
    public function it_removes_ssl_dir_and_deletes_certificates_when_ssl_disabled()
    {
        $db = $this->fakeDatabase(['enable_ssl' => false, 'uuid' => 'pg-ssl']);
        $this->seedResourceCertificate($this->createTestServer(), StandalonePostgresql::class, $db->id, 'pg-ssl');

        $this->action->handle($db);

        $this->assertSame(
            0,
            SslCertificate::where('resource_type', StandalonePostgresql::class)->where('resource_id', $db->id)->count(),
            'sslCertificates()->delete() should have removed the certificate'
        );

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $commands = $calls[0][0];
        $this->assertStringContainsString('rm -rf /etc/coolify/databases/pg-ssl/ssl', implode("\n", $commands));
    }

    #[Test]
    public function it_sets_up_ssl_and_generates_certificate_when_missing()
    {
        $server = $this->createTestServer();
        $this->seedCaCertificate($server);

        $db = $this->fakeDatabase(['enable_ssl' => true, 'uuid' => 'pg-ssl-2']);
        $this->seedResourceCertificate($server, StandalonePostgresql::class, $db->id, 'pg-ssl-2');
        $db->setRelation('destination', $this->destinationWithServer($server));

        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls, 'remote_process should be called');

        $commands = $calls[0][0];
        $this->assertStringContainsString("echo 'Setting up SSL for this database.'", implode("\n", $commands));
        $this->assertStringContainsString('docker compose -f', implode("\n", $commands));
    }

    #[Test]
    public function it_returns_null_when_ssl_enabled_but_server_missing()
    {
        $db = $this->fakeDatabase(['enable_ssl' => true, 'uuid' => 'pg-no-server']);
        // destinationWithoutServer() leaves ->server as null.

        $result = $this->action->handle($db);

        $this->assertNull($result);
        $this->assertEmpty(DatabaseActionFake::$remoteProcessCalls);
    }

    #[Test]
    public function it_writes_custom_postgres_conf_and_adds_listen_addresses_when_missing()
    {
        $db = $this->fakeDatabase(['postgres_conf' => 'max_connections = 200', 'uuid' => 'pg-conf']);
        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $commands = $calls[0][0];
        $foundWrite = false;
        foreach ($commands as $cmd) {
            if (str_contains($cmd, 'custom-postgres.conf')) {
                $foundWrite = true;
                $this->assertStringContainsString('base64 -d', $cmd);
                if (preg_match("/echo '(.+)' \| base64 -d/", $cmd, $m)) {
                    $decoded = base64_decode($m[1]);
                    $this->assertStringContainsString('max_connections = 200', $decoded);
                    $this->assertStringContainsString("listen_addresses = '*'", $decoded);
                }
            }
        }
        $this->assertTrue($foundWrite, 'custom-postgres.conf write command should be present');

        $db->refresh();
        $this->assertStringContainsString("listen_addresses = '*'", $db->postgres_conf);
    }

    #[Test]
    public function it_removes_custom_conf_file_when_postgres_conf_is_blank()
    {
        $db = $this->fakeDatabase(['postgres_conf' => null, 'uuid' => 'pg-no-conf']);
        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $commands = $calls[0][0];
        $this->assertStringContainsString('rm -f /etc/coolify/databases/pg-no-conf/custom-postgres.conf', implode("\n", $commands));
    }

    #[Test]
    public function it_writes_init_scripts_and_clears_directory_first()
    {
        $db = $this->fakeDatabase([
            'uuid' => 'pg-init',
            'init_scripts' => [
                ['filename' => 'init.sql', 'content' => 'SELECT 1;'],
            ],
        ]);
        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $commands = $calls[0][0];
        $joined = implode("\n", $commands);
        $this->assertStringContainsString('rm -rf /etc/coolify/databases/pg-init/docker-entrypoint-initdb.d/*', $joined);

        $foundInitScript = false;
        foreach ($commands as $cmd) {
            if (str_contains($cmd, 'docker-entrypoint-initdb.d/init.sql')) {
                $foundInitScript = true;
                $this->assertStringContainsString('base64 -d', $cmd);
            }
        }
        $this->assertTrue($foundInitScript, 'init script write command should be present');
    }

    #[Test]
    public function it_generates_docker_compose_and_includes_persistent_and_file_volumes()
    {
        $db = $this->fakeDatabase([
            'uuid' => 'pg-999',
        ]);

        $db->setRelation('persistentStorages', collect([
            (object) [
                'host_path' => '/host/path',
                'mount_path' => '/var/lib/postgresql/data',
                'name' => 'vol1',
            ],
        ]));
        $db->setRelation('runtime_environment_variables', collect([
            (object) ['key' => 'FOO', 'real_value' => 'bar'],
        ]));

        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $commands = $calls[0][0];
        $found = false;
        foreach ($commands as $cmd) {
            if (str_contains($cmd, 'docker-compose.yml') && str_contains($cmd, 'base64 -d')) {
                $found = true;
                if (preg_match("/echo '(.+)' \| base64 -d \| tee .*docker-compose.yml/", $cmd, $m)) {
                    $decoded = base64_decode($m[1]);
                    $yaml = Yaml::parse($decoded);
                    $this->assertArrayHasKey('services', $yaml);
                    $this->assertArrayHasKey('pg-999', $yaml['services']);
                    $this->assertContains('/host/path:/var/lib/postgresql/data', $yaml['services']['pg-999']['volumes']);
                }
                break;
            }
        }
        $this->assertTrue($found, 'docker-compose write command should be present');
    }

    #[Test]
    public function it_adds_postgres_env_vars_when_missing_and_preserves_existing()
    {
        $db = new StandalonePostgresql([
            'postgres_user' => 'pguser',
            'postgres_password' => 'pgpass',
            'postgres_db' => 'pgdb',
        ]);
        $db->setRelation('runtime_environment_variables', collect([
            (object) ['key' => 'BAR', 'real_value' => 'baz'],
        ]));

        $this->action->database = $db;
        $env = $this->callProtected($this->action, 'generate_environment_variables');

        $this->assertContains('POSTGRES_USER=pguser', $env);
        $this->assertContains('PGUSER=pguser', $env);
        $this->assertContains('POSTGRES_PASSWORD=pgpass', $env);
        $this->assertContains('POSTGRES_DB=pgdb', $env);
        $this->assertContains('BAR=baz', $env);
    }

    #[Test]
    public function it_unsets_healthcheck_when_disabled()
    {
        $db = $this->fakeDatabase(['uuid' => 'pg-health']);
        // No health_check_enabled column exists on standalone_postgresqls; HasDatabaseHealthCheck
        // reads it as a plain dynamic attribute, so setting it directly is sufficient.
        $db->health_check_enabled = false;

        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $found = false;
        foreach ($calls[0][0] as $cmd) {
            if (preg_match("/echo '(.+)' \| base64 -d \| tee .*docker-compose.yml/", $cmd, $m)) {
                $decoded = base64_decode($m[1]);
                $yaml = Yaml::parse($decoded);
                $this->assertArrayHasKey('pg-health', $yaml['services']);
                $this->assertArrayNotHasKey('healthcheck', $yaml['services']['pg-health']);
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'docker-compose write command should be present');
    }

    #[Test]
    public function it_adds_logging_when_server_and_db_enable_log_drain()
    {
        $server = $this->createTestServer();
        $server->settings->update(['is_logdrain_custom_enabled' => true]);

        $db = $this->fakeDatabase(['uuid' => 'pg-log', 'is_log_drain_enabled' => true]);
        $db->setRelation('destination', $this->destinationWithServer($server));

        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $found = false;
        foreach ($calls[0][0] as $cmd) {
            if (preg_match("/echo '(.+)' \| base64 -d \| tee .*docker-compose.yml/", $cmd, $m)) {
                $decoded = base64_decode($m[1]);
                $yaml = Yaml::parse($decoded);
                $this->assertArrayHasKey('logging', $yaml['services']['pg-log']);
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'docker-compose write command should be present');
    }

    #[Test]
    public function it_sets_ssl_command_and_chowns_certs_when_ssl_enabled()
    {
        $server = $this->createTestServer();
        $this->seedCaCertificate($server);

        $db = $this->fakeDatabase(['enable_ssl' => true, 'uuid' => 'pg-ssl-3']);
        $this->seedResourceCertificate($server, StandalonePostgresql::class, $db->id, 'pg-ssl-3');
        $db->setRelation('destination', $this->destinationWithServer($server));

        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $commands = $calls[0][0];
        $joined = implode("\n", $commands);
        $this->assertStringContainsString('docker exec pg-ssl-3 bash -c', $joined);
        $this->assertStringContainsString('chown', $joined);
        $this->assertStringContainsString('pguser', $joined);
        $this->assertStringContainsString('/var/lib/postgresql/certs/server.crt', $joined);
        $this->assertStringContainsString('/var/lib/postgresql/certs/server.key', $joined);

        foreach ($commands as $cmd) {
            if (preg_match("/echo '(.+)' \| base64 -d \| tee .*docker-compose.yml/", $cmd, $m)) {
                $decoded = base64_decode($m[1]);
                $yaml = Yaml::parse($decoded);
                $this->assertSame([
                    'postgres',
                    '-c', 'ssl=on',
                    '-c', 'ssl_cert_file=/var/lib/postgresql/certs/server.crt',
                    '-c', 'ssl_key_file=/var/lib/postgresql/certs/server.key',
                ], $yaml['services']['pg-ssl-3']['command']);
                break;
            }
        }
    }
}
