<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Database;

use App\Models\LocalFileVolume;
use App\Models\SslCertificate;
use App\Models\StandaloneMongodb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Fakes\DatabaseActionFake;
use Tests\Support\InteractsWithDatabaseActions;
use Tests\TestCase;

final class StartMongodbTest extends TestCase
{
    use InteractsWithDatabaseActions;
    use RefreshDatabase;

    #[Test]
    public function it_removes_ssl_dir_and_deletes_certificates_when_ssl_disabled()
    {
        $db = $this->fakeDatabase(['enable_ssl' => false, 'uuid' => 'mongo-ssl']);
        $this->seedResourceCertificate($this->createTestServer(), StandaloneMongodb::class, $db->id, 'mongo-ssl');

        $this->action->handle($db);

        $this->assertSame(
            0,
            SslCertificate::where('resource_type', StandaloneMongodb::class)->where('resource_id', $db->id)->count(),
            'sslCertificates()->delete() should have removed the certificate'
        );

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $commands = $calls[0][0];
        $this->assertStringContainsString('rm -rf /etc/coolify/databases/mongo-ssl/ssl', implode("\n", $commands));
    }

    #[Test]
    public function it_sets_up_ssl_and_generates_certificate_when_missing()
    {
        $server = $this->createTestServer();
        $this->seedCaCertificate($server);

        $db = $this->fakeDatabase(['enable_ssl' => true, 'uuid' => 'mongo-ssl-2']);
        $this->seedResourceCertificate($server, StandaloneMongodb::class, $db->id, 'mongo-ssl-2');
        $db->setRelation('destination', $this->destinationWithServer($server));

        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls, 'remote_process should be called');

        $commands = $calls[0][0];
        $this->assertStringContainsString("echo 'Setting up SSL for this database.'", implode("\n", $commands));
        $this->assertStringContainsString('docker compose -f', implode("\n", $commands));
    }

    #[Test]
    public function it_writes_mongo_conf_and_sets_command_when_mongo_conf_present()
    {
        $db = $this->fakeDatabase(['mongo_conf' => "storage:\n  dbPath: /data/db\n", 'uuid' => 'mongo-conf']);
        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $commands = $calls[0][0];
        $foundWrite = false;
        foreach ($commands as $cmd) {
            if (str_contains($cmd, 'mongod.conf')) {
                $foundWrite = true;
                $this->assertStringContainsString('base64 -d', $cmd);
            }
        }
        $this->assertTrue($foundWrite, 'mongod.conf write command should be present');

        $found = false;
        foreach ($commands as $cmd) {
            if (preg_match("/echo '(.+)' \| base64 -d \| tee .*docker-compose.yml/", $cmd, $m)) {
                $decoded = base64_decode($m[1]);
                $yaml = Yaml::parse($decoded);
                $this->assertArrayHasKey('mongo-conf', $yaml['services']);
                $this->assertSame(['mongod', '--config', '/etc/mongo/mongod.conf'], $yaml['services']['mongo-conf']['command']);
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'docker-compose write command should be present');
    }

    #[Test]
    public function it_adds_default_database_init_files_and_volumes()
    {
        $db = $this->fakeDatabase(['uuid' => 'mongo-init']);
        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $commands = $calls[0][0];
        $this->assertStringContainsString('mkdir -p /etc/coolify/databases/mongo-init/docker-entrypoint-initdb.d', implode("\n", $commands));
        $this->assertStringContainsString('01-default-database.js', implode("\n", $commands));
    }

    #[Test]
    public function it_generates_docker_compose_and_includes_persistent_and_file_volumes()
    {
        // LocalFileVolume::create() dispatches ServerStorageSaveJob on its "created" event
        // unconditionally; fake the queue so it doesn't actually run against our fixture.
        Queue::fake();

        $db = $this->fakeDatabase([
            'uuid' => 'mongo-999',
        ]);

        $db->setRelation('persistentStorages', collect([
            (object) [
                'host_path' => '/host/path',
                'mount_path' => '/data/db',
                'name' => 'vol1',
            ],
        ]));
        $db->setRelation('runtime_environment_variables', collect([
            (object) ['key' => 'FOO', 'real_value' => 'bar'],
        ]));

        LocalFileVolume::create([
            'fs_path' => '/fs/path',
            'mount_path' => '/etc/mongo/certs/server.pem',
            'resource_type' => StandaloneMongodb::class,
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
                    $this->assertArrayHasKey('mongo-999', $yaml['services']);
                    $this->assertContains('/host/path:/data/db', $yaml['services']['mongo-999']['volumes']);
                }
                break;
            }
        }
        $this->assertTrue($found, 'docker-compose write command should be present');
    }

    #[Test]
    public function it_builds_ssl_command_for_verify_full_mode()
    {
        $server = $this->createTestServer();
        $this->seedCaCertificate($server);

        $db = $this->fakeDatabase([
            'enable_ssl' => true,
            'uuid' => 'mongo-ssl-mode',
            'ssl_mode' => 'verify-full',
            'mongo_conf' => null,
        ]);
        $this->seedResourceCertificate($server, StandaloneMongodb::class, $db->id, 'mongo-ssl-mode');
        $db->setRelation('destination', $this->destinationWithServer($server));

        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $found = false;
        foreach ($calls[0][0] as $cmd) {
            if (preg_match("/echo '(.+)' \| base64 -d \| tee .*docker-compose.yml/", $cmd, $m)) {
                $decoded = base64_decode($m[1]);
                $yaml = Yaml::parse($decoded);
                $cmdArray = $yaml['services']['mongo-ssl-mode']['command'];
                $this->assertIsArray($cmdArray);
                $this->assertContains('--tlsMode=requireTLS', $cmdArray);
                $this->assertContains('--tlsCAFile', $cmdArray);
                $this->assertContains('/etc/mongo/certs/ca.pem', $cmdArray);
                $this->assertContains('--tlsCertificateKeyFile', $cmdArray);
                $this->assertContains('/etc/mongo/certs/server.pem', $cmdArray);
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'docker-compose write command should be present');
    }

    #[Test]
    public function it_adds_mongo_env_vars_when_missing_and_preserves_existing()
    {
        $db = new StandaloneMongodb([
            'mongo_initdb_root_username' => 'admin',
            // mongoInitdbRootPassword()'s get accessor self-heals legacy plaintext passwords by
            // encrypting and calling $this->save() on decrypt failure — fatal here since this
            // fixture is never persisted. Pre-encrypting avoids that path entirely.
            'mongo_initdb_root_password' => encrypt('pw'),
            'mongo_initdb_database' => 'appdb',
        ]);
        $db->setRelation('runtime_environment_variables', collect([
            (object) ['key' => 'BAR', 'real_value' => 'baz'],
        ]));

        $this->action->database = $db;
        $env = $this->callProtected($this->action, 'generate_environment_variables');

        $this->assertContains('MONGO_INITDB_ROOT_USERNAME=admin', $env);
        $this->assertContains('MONGO_INITDB_ROOT_PASSWORD=pw', $env);
        $this->assertContains('MONGO_INITDB_DATABASE=appdb', $env);
        $this->assertContains('BAR=baz', $env);
    }

    #[Test]
    public function it_unsets_healthcheck_when_disabled()
    {
        $db = $this->fakeDatabase(['uuid' => 'mongo-health']);
        // No health_check_enabled column exists on standalone_mongodbs; HasDatabaseHealthCheck
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
                $this->assertArrayHasKey('mongo-health', $yaml['services']);
                $this->assertArrayNotHasKey('healthcheck', $yaml['services']['mongo-health']);
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

        $db = $this->fakeDatabase(['uuid' => 'mongo-log', 'is_log_drain_enabled' => true]);
        $db->setRelation('destination', $this->destinationWithServer($server));

        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $found = false;
        foreach ($calls[0][0] as $cmd) {
            if (preg_match("/echo '(.+)' \| base64 -d \| tee .*docker-compose.yml/", $cmd, $m)) {
                $decoded = base64_decode($m[1]);
                $yaml = Yaml::parse($decoded);
                $this->assertArrayHasKey('logging', $yaml['services']['mongo-log']);
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'docker-compose write command should be present');
    }
}
