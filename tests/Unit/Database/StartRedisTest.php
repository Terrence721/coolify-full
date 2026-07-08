<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Database;

use App\Models\SslCertificate;
use App\Models\StandaloneRedis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Fakes\DatabaseActionFake;
use Tests\Support\InteractsWithDatabaseActions;
use Tests\TestCase;

final class StartRedisTest extends TestCase
{
    use InteractsWithDatabaseActions;
    use RefreshDatabase;

    #[Test]
    public function it_removes_ssl_dir_and_deletes_certificates_when_ssl_disabled()
    {
        $db = $this->fakeDatabase(['enable_ssl' => false, 'uuid' => 'redis-ssl']);
        $this->seedResourceCertificate($this->createTestServer(), StandaloneRedis::class, $db->id, 'redis-ssl');

        $this->action->handle($db);

        $this->assertSame(
            0,
            SslCertificate::where('resource_type', StandaloneRedis::class)->where('resource_id', $db->id)->count(),
            'sslCertificates()->delete() should have removed the certificate'
        );

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $commands = $calls[0][0];
        $this->assertStringContainsString('rm -rf /etc/coolify/databases/redis-ssl/ssl', implode("\n", $commands));
    }

    #[Test]
    public function it_sets_up_ssl_and_generates_certificate_when_missing()
    {
        $server = $this->createTestServer();
        $this->seedCaCertificate($server);

        $db = $this->fakeDatabase(['enable_ssl' => true, 'uuid' => 'redis-ssl-2']);
        $this->seedResourceCertificate($server, StandaloneRedis::class, $db->id, 'redis-ssl-2');
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
        $db = $this->fakeDatabase(['enable_ssl' => true, 'uuid' => 'redis-no-server']);
        // destinationWithoutServer() leaves ->server as null.

        $result = $this->action->handle($db);

        $this->assertNull($result);
        $this->assertEmpty(DatabaseActionFake::$remoteProcessCalls);
    }

    #[Test]
    public function it_writes_custom_redis_conf_and_chowns_it()
    {
        $db = $this->fakeDatabase(['redis_conf' => 'maxmemory 256mb', 'uuid' => 'redis-conf']);
        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $commands = $calls[0][0];
        $joined = implode("\n", $commands);

        $foundWrite = false;
        foreach ($commands as $cmd) {
            if (str_contains($cmd, 'redis.conf') && str_contains($cmd, 'base64 -d')) {
                $foundWrite = true;
            }
        }
        $this->assertTrue($foundWrite, 'redis.conf write command should be present');
        $this->assertStringContainsString('chown 999:999 /etc/coolify/databases/redis-conf/redis.conf', $joined);
    }

    #[Test]
    public function it_builds_start_command_without_redis_conf_and_without_ssl()
    {
        $db = $this->fakeDatabase(['redis_conf' => null, 'enable_ssl' => false]);
        $this->seedEnvironmentVariable($db, 'REDIS_PASSWORD', 'pass123');
        $this->action->database = $db;

        $command = $this->callProtected($this->action, 'buildStartCommand');

        $this->assertStringContainsString('--requirepass pass123', $command);
        $this->assertStringContainsString('--appendonly yes', $command);
    }

    #[Test]
    public function it_builds_start_command_with_redis_conf_and_requirepass_present()
    {
        $db = $this->fakeDatabase(['redis_conf' => 'requirepass abc', 'enable_ssl' => false]);
        $this->seedEnvironmentVariable($db, 'REDIS_PASSWORD', 'pass123');
        $this->action->database = $db;

        $command = $this->callProtected($this->action, 'buildStartCommand');

        $this->assertStringContainsString('redis-server /usr/local/etc/redis/redis.conf', $command);
        $this->assertStringNotContainsString('--requirepass', $command);
    }

    #[Test]
    public function it_builds_start_command_adds_ssl_arguments()
    {
        $db = $this->fakeDatabase(['redis_conf' => null, 'enable_ssl' => true]);
        $this->seedEnvironmentVariable($db, 'REDIS_PASSWORD', 'pass123');
        $this->action->database = $db;

        $command = $this->callProtected($this->action, 'buildStartCommand');

        $this->assertStringContainsString('--tls-port 6380', $command);
        $this->assertStringContainsString('--tls-cert-file', $command);
        $this->assertStringContainsString('--tls-key-file', $command);
        $this->assertStringContainsString('--tls-ca-cert-file', $command);
    }

    #[Test]
    public function it_generates_docker_compose_and_includes_persistent_and_file_volumes()
    {
        $db = $this->fakeDatabase([
            'uuid' => 'redis-999',
        ]);

        $db->setRelation('persistentStorages', collect([
            (object) [
                'host_path' => '/host/path',
                'mount_path' => '/data',
                'name' => 'vol1',
            ],
        ]));
        $db->setRelation('runtime_environment_variables', collect([
            (object) ['key' => 'FOO', 'real_value' => 'bar', 'is_shared' => false],
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
                    $this->assertArrayHasKey('redis-999', $yaml['services']);
                    $this->assertContains('/host/path:/data', $yaml['services']['redis-999']['volumes']);
                }
                break;
            }
        }
        $this->assertTrue($found, 'docker-compose write command should be present');
    }

    #[Test]
    public function it_includes_shared_redis_password_env_var()
    {
        $db = $this->fakeDatabase();
        $db->setRelation('runtime_environment_variables', collect([
            (object) ['key' => 'REDIS_PASSWORD', 'real_value' => 'sharedpass', 'is_shared' => true],
        ]));

        $this->action->database = $db;
        $env = $this->callProtected($this->action, 'generate_environment_variables');

        $this->assertContains('REDIS_PASSWORD=sharedpass', $env);
    }

    #[Test]
    public function it_unsets_healthcheck_when_disabled()
    {
        $db = $this->fakeDatabase(['uuid' => 'redis-health']);
        // No health_check_enabled column exists on standalone_redis; HasDatabaseHealthCheck
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
                $this->assertArrayHasKey('redis-health', $yaml['services']);
                $this->assertArrayNotHasKey('healthcheck', $yaml['services']['redis-health']);
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

        $db = $this->fakeDatabase(['uuid' => 'redis-log', 'is_log_drain_enabled' => true]);
        $db->setRelation('destination', $this->destinationWithServer($server));

        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $found = false;
        foreach ($calls[0][0] as $cmd) {
            if (preg_match("/echo '(.+)' \| base64 -d \| tee .*docker-compose.yml/", $cmd, $m)) {
                $decoded = base64_decode($m[1]);
                $yaml = Yaml::parse($decoded);
                $this->assertArrayHasKey('logging', $yaml['services']['redis-log']);
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'docker-compose write command should be present');
    }

    #[Test]
    public function it_chowns_ssl_certs_when_ssl_enabled()
    {
        $server = $this->createTestServer();
        $this->seedCaCertificate($server);

        $db = $this->fakeDatabase(['enable_ssl' => true, 'uuid' => 'redis-ssl-3']);
        $this->seedResourceCertificate($server, StandaloneRedis::class, $db->id, 'redis-ssl-3');
        $db->setRelation('destination', $this->destinationWithServer($server));

        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $joined = implode("\n", $calls[0][0]);
        $this->assertStringContainsString('chown -R 999:999 /etc/coolify/databases/redis-ssl-3/ssl/server.key /etc/coolify/databases/redis-ssl-3/ssl/server.crt', $joined);

        foreach ($calls[0][0] as $cmd) {
            if (preg_match("/echo '(.+)' \| base64 -d \| tee .*docker-compose.yml/", $cmd, $m)) {
                $decoded = base64_decode($m[1]);
                $yaml = Yaml::parse($decoded);
                $this->assertStringContainsString('--tls-port 6380', $yaml['services']['redis-ssl-3']['command']);
                break;
            }
        }
    }
}
