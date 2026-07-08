<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Database;

use App\Models\LocalFileVolume;
use App\Models\SslCertificate;
use App\Models\StandaloneKeydb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Fakes\DatabaseActionFake;
use Tests\Support\InteractsWithDatabaseActions;
use Tests\TestCase;

final class StartKeydbTest extends TestCase
{
    use InteractsWithDatabaseActions;
    use RefreshDatabase;

    #[Test]
    public function it_builds_start_command_without_keydb_conf_and_without_ssl()
    {
        $db = $this->fakeDatabase(['keydb_conf' => null, 'keydb_password' => 'pw']);
        $this->action->handle($db);

        $command = $this->callProtected($this->action, 'buildStartCommand');
        $this->assertSame('keydb-server --requirepass pw --appendonly yes', $command);
        $this->assertStringNotContainsString('--tls', $command);
    }

    #[Test]
    public function it_builds_start_command_with_keydb_conf_that_contains_requirepass()
    {
        $db = new StandaloneKeydb([
            'keydb_conf' => "bind 0.0.0.0\nrequirepass mypass\n",
            'keydb_password' => 'pw',
            'enable_ssl' => false,
        ]);
        $this->action->database = $db;

        $command = $this->callProtected($this->action, 'buildStartCommand');
        $this->assertSame('keydb-server /etc/keydb/keydb.conf', $command);
    }

    #[Test]
    public function it_builds_start_command_with_keydb_conf_without_requirepass()
    {
        $db = new StandaloneKeydb([
            'keydb_conf' => "bind 0.0.0.0\n",
            'keydb_password' => 'pw',
            'enable_ssl' => false,
        ]);
        $this->action->database = $db;

        $command = $this->callProtected($this->action, 'buildStartCommand');
        $this->assertSame('keydb-server /etc/keydb/keydb.conf --requirepass pw', $command);
    }

    #[Test]
    public function it_appends_ssl_args_when_ssl_enabled()
    {
        $db = new StandaloneKeydb(['enable_ssl' => true, 'keydb_password' => 'pw']);
        $this->action->database = $db;

        $command = $this->callProtected($this->action, 'buildStartCommand');
        $this->assertStringContainsString('--tls-port 6380', $command);
        $this->assertStringContainsString('--tls-cert-file /etc/keydb/certs/server.crt', $command);
        $this->assertStringContainsString('--tls-ca-cert-file /etc/keydb/certs/coolify-ca.crt', $command);
    }

    #[Test]
    public function it_removes_ssl_files_and_deletes_certificates_when_ssl_disabled()
    {
        $db = $this->fakeDatabase(['enable_ssl' => false, 'uuid' => 'kb-ssl']);
        $this->seedResourceCertificate($this->createTestServer(), StandaloneKeydb::class, $db->id, 'kb-ssl');

        $this->action->handle($db);

        $this->assertSame(
            0,
            SslCertificate::where('resource_type', StandaloneKeydb::class)->where('resource_id', $db->id)->count(),
            'sslCertificates()->delete() should have removed the certificate'
        );

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $commands = $calls[0][0];
        $this->assertStringContainsString('rm -rf /etc/coolify/databases/kb-ssl/ssl', implode("\n", $commands));
    }

    #[Test]
    public function it_sets_up_ssl_and_generates_certificate_when_missing()
    {
        $server = $this->createTestServer();
        $this->seedCaCertificate($server);

        $db = $this->fakeDatabase(['enable_ssl' => true, 'uuid' => 'kb-ssl-2']);
        $this->seedResourceCertificate($server, StandaloneKeydb::class, $db->id, 'kb-ssl-2');
        $db->setRelation('destination', $this->destinationWithServer($server));

        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls, 'remote_process should be called');

        $commands = $calls[0][0];
        $this->assertStringContainsString("echo 'Setting up SSL for this database.'", implode("\n", $commands));
        $this->assertStringContainsString('docker compose -f', implode("\n", $commands));
    }

    #[Test]
    public function it_writes_keydb_conf_and_chowns_when_keydb_conf_present()
    {
        $db = $this->fakeDatabase(['keydb_conf' => "appendonly yes\n", 'uuid' => 'kb-conf']);
        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $commands = $calls[0][0];
        $joined = implode("\n", $commands);

        $foundConfWrite = false;
        foreach ($commands as $cmd) {
            if (str_contains($cmd, '/keydb.conf') && str_contains($cmd, 'base64 -d')) {
                $foundConfWrite = true;
            }
        }
        $this->assertTrue($foundConfWrite, 'keydb.conf write command should be present');
        $this->assertStringContainsString('chown 999:999 /etc/coolify/databases/kb-conf/keydb.conf', $joined);
    }

    #[Test]
    public function it_generates_docker_compose_and_includes_persistent_and_file_volumes()
    {
        // LocalFileVolume::create() dispatches ServerStorageSaveJob on its "created" event
        // unconditionally; fake the queue so it doesn't actually run against our fixture.
        Queue::fake();

        $db = $this->fakeDatabase([
            'uuid' => 'kb-999',
        ]);

        $db->setRelation('persistentStorages', collect([
            (object) [
                'host_path' => '/host/path',
                'mount_path' => '/data',
                'name' => 'vol1',
            ],
        ]));
        $db->setRelation('runtime_environment_variables', collect([
            (object) ['key' => 'FOO', 'real_value' => 'bar'],
        ]));

        LocalFileVolume::create([
            'fs_path' => '/fs/path',
            'mount_path' => '/etc/keydb/certs/server.crt',
            'resource_type' => StandaloneKeydb::class,
            'resource_id' => $db->id,
        ]);

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
                    $this->assertArrayHasKey('kb-999', $yaml['services']);
                    $this->assertContains('/host/path:/data', $yaml['services']['kb-999']['volumes']);
                }
                break;
            }
        }
        $this->assertTrue($found, 'docker-compose write command should be present');
    }

    #[Test]
    public function it_adds_redis_password_env_if_missing_and_preserves_existing_envs()
    {
        $db = new StandaloneKeydb(['keydb_password' => 'pw123']);
        $db->setRelation('runtime_environment_variables', collect([
            (object) ['key' => 'BAR', 'real_value' => 'baz'],
        ]));

        $this->action->database = $db;
        $env = $this->callProtected($this->action, 'generate_environment_variables');

        $this->assertContains('REDIS_PASSWORD=pw123', $env);
        $this->assertContains('BAR=baz', $env);
    }

    #[Test]
    public function it_unsets_healthcheck_when_disabled()
    {
        $db = $this->fakeDatabase(['uuid' => 'kb-health']);
        // No health_check_enabled column exists on standalone_keydbs; HasDatabaseHealthCheck
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
                $this->assertArrayHasKey('kb-health', $yaml['services']);
                $this->assertArrayNotHasKey('healthcheck', $yaml['services']['kb-health']);
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

        $db = $this->fakeDatabase(['uuid' => 'kb-log', 'is_log_drain_enabled' => true]);
        $db->setRelation('destination', $this->destinationWithServer($server));

        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $found = false;
        foreach ($calls[0][0] as $cmd) {
            if (preg_match("/echo '(.+)' \| base64 -d \| tee .*docker-compose.yml/", $cmd, $m)) {
                $decoded = base64_decode($m[1]);
                $yaml = Yaml::parse($decoded);
                $this->assertArrayHasKey('logging', $yaml['services']['kb-log']);
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'docker-compose write command should be present');
    }
}
