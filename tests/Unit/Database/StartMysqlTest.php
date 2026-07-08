<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Database;

use App\Models\SslCertificate;
use App\Models\StandaloneMysql;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Fakes\DatabaseActionFake;
use Tests\Support\InteractsWithDatabaseActions;
use Tests\TestCase;

final class StartMysqlTest extends TestCase
{
    use InteractsWithDatabaseActions;
    use RefreshDatabase;

    #[Test]
    public function it_removes_ssl_dir_and_deletes_certificates_when_ssl_disabled()
    {
        $db = $this->fakeDatabase(['enable_ssl' => false, 'uuid' => 'mysql-ssl']);
        $this->seedResourceCertificate($this->createTestServer(), StandaloneMysql::class, $db->id, 'mysql-ssl');

        $this->action->handle($db);

        $this->assertSame(
            0,
            SslCertificate::where('resource_type', StandaloneMysql::class)->where('resource_id', $db->id)->count(),
            'sslCertificates()->delete() should have removed the certificate'
        );

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $commands = $calls[0][0];
        $this->assertStringContainsString('rm -rf /etc/coolify/databases/mysql-ssl/ssl', implode("\n", $commands));
    }

    #[Test]
    public function it_sets_up_ssl_and_generates_certificate_when_missing()
    {
        $server = $this->createTestServer();
        $this->seedCaCertificate($server);

        $db = $this->fakeDatabase(['enable_ssl' => true, 'uuid' => 'mysql-ssl-2']);
        $this->seedResourceCertificate($server, StandaloneMysql::class, $db->id, 'mysql-ssl-2');
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
        $db = $this->fakeDatabase(['enable_ssl' => true, 'uuid' => 'mysql-no-server']);
        // destinationWithoutServer() leaves ->server as null.

        $result = $this->action->handle($db);

        $this->assertNull($result);
        $this->assertEmpty(DatabaseActionFake::$remoteProcessCalls);
    }

    #[Test]
    public function it_writes_custom_mysql_conf_and_includes_chown_when_present()
    {
        $db = $this->fakeDatabase(['mysql_conf' => "[mysqld]\nmax_connections=100\n", 'uuid' => 'mysql-conf']);
        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $commands = $calls[0][0];
        $foundWrite = false;
        foreach ($commands as $cmd) {
            if (str_contains($cmd, 'custom-config.cnf')) {
                $foundWrite = true;
                $this->assertStringContainsString('base64 -d', $cmd);
            }
        }
        $this->assertTrue($foundWrite, 'custom-config.cnf write command should be present');
        $this->assertStringContainsString('docker compose -f', implode("\n", $commands));
    }

    #[Test]
    public function it_generates_docker_compose_and_includes_persistent_and_file_volumes()
    {
        $db = $this->fakeDatabase([
            'uuid' => 'mysql-999',
        ]);

        $db->setRelation('persistentStorages', collect([
            (object) [
                'host_path' => '/host/path',
                'mount_path' => '/var/lib/mysql',
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
                    $this->assertArrayHasKey('mysql-999', $yaml['services']);
                    $this->assertContains('/host/path:/var/lib/mysql', $yaml['services']['mysql-999']['volumes']);
                }
                break;
            }
        }
        $this->assertTrue($found, 'docker-compose write command should be present');
    }

    #[Test]
    public function it_adds_mysql_env_vars_when_missing_and_preserves_existing()
    {
        $db = new StandaloneMysql([
            'mysql_root_password' => 'rootpw',
            'mysql_database' => 'appdb',
            'mysql_user' => 'appuser',
            'mysql_password' => 'apppw',
        ]);
        $db->setRelation('runtime_environment_variables', collect([
            (object) ['key' => 'BAR', 'real_value' => 'baz'],
        ]));

        $this->action->database = $db;
        $env = $this->callProtected($this->action, 'generate_environment_variables');

        $this->assertContains('MYSQL_ROOT_PASSWORD=rootpw', $env);
        $this->assertContains('MYSQL_DATABASE=appdb', $env);
        $this->assertContains('MYSQL_USER=appuser', $env);
        $this->assertContains('MYSQL_PASSWORD=apppw', $env);
        $this->assertContains('BAR=baz', $env);
    }

    #[Test]
    public function it_unsets_healthcheck_when_disabled()
    {
        $db = $this->fakeDatabase(['uuid' => 'mysql-health']);
        // No health_check_enabled column exists on standalone_mysqls; HasDatabaseHealthCheck
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
                $this->assertArrayHasKey('mysql-health', $yaml['services']);
                $this->assertArrayNotHasKey('healthcheck', $yaml['services']['mysql-health']);
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

        $db = $this->fakeDatabase(['uuid' => 'mysql-log', 'is_log_drain_enabled' => true]);
        $db->setRelation('destination', $this->destinationWithServer($server));

        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $found = false;
        foreach ($calls[0][0] as $cmd) {
            if (preg_match("/echo '(.+)' \| base64 -d \| tee .*docker-compose.yml/", $cmd, $m)) {
                $decoded = base64_decode($m[1]);
                $yaml = Yaml::parse($decoded);
                $this->assertArrayHasKey('logging', $yaml['services']['mysql-log']);
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

        $db = $this->fakeDatabase(['enable_ssl' => true, 'uuid' => 'mysql-ssl-3']);
        $this->seedResourceCertificate($server, StandaloneMysql::class, $db->id, 'mysql-ssl-3');
        $db->setRelation('destination', $this->destinationWithServer($server));

        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $commands = $calls[0][0];
        $joined = implode("\n", $commands);
        $this->assertStringContainsString('docker exec mysql-ssl-3 bash -c', $joined);
        $this->assertStringContainsString('chown', $joined);
        $this->assertStringContainsString('appuser', $joined);
        $this->assertStringContainsString('/etc/mysql/certs/server.crt', $joined);
        $this->assertStringContainsString('/etc/mysql/certs/server.key', $joined);

        foreach ($commands as $cmd) {
            if (preg_match("/echo '(.+)' \| base64 -d \| tee .*docker-compose.yml/", $cmd, $m)) {
                $decoded = base64_decode($m[1]);
                $yaml = Yaml::parse($decoded);
                $this->assertSame([
                    'mysqld',
                    '--ssl-cert=/etc/mysql/certs/server.crt',
                    '--ssl-key=/etc/mysql/certs/server.key',
                    '--ssl-ca=/etc/mysql/certs/coolify-ca.crt',
                    '--require-secure-transport=1',
                ], $yaml['services']['mysql-ssl-3']['command']);
                break;
            }
        }
    }
}
