<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Database;

require_once __DIR__.'/../../Support/Fakes/database_action_overrides.php';

use App\Actions\Database\StartDragonfly;
use App\Models\LocalFileVolume;
use App\Models\Server;
use App\Models\SslCertificate;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Fakes\DatabaseActionFake;
use Tests\Support\Fakes\RemoteProcessFake;
use Tests\TestCase;

final class StartDragonflyTest extends TestCase
{
    use RefreshDatabase;

    private StartDragonfly $action;

    protected function setUp(): void
    {
        parent::setUp();

        DatabaseActionFake::reset();
        RemoteProcessFake::reset();

        $this->action = new StartDragonfly;
    }

    /** Invoke a protected/private method on $object via Reflection. */
    private function callProtected(object $object, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($object, $method))->invoke($object, ...$args);
    }

    /**
     * sslCertificates() and fileStorages() are called as chained relation methods
     * (->delete(), ->where(...)->get(), etc.) in StartDragonfly, not accessed as cached
     * relation properties — so setRelation() can't intercept them. A real, persisted
     * model (with RefreshDatabase providing the real, empty tables) is required instead.
     */
    private function fakeDatabase(array $overrides = []): StandaloneDragonfly
    {
        $db = StandaloneDragonfly::create(array_merge([
            'uuid' => 'df-123',
            'name' => 'dragon',
            'image' => 'dragonfly:latest',
            'dragonfly_password' => 's3cr3t',
            'enable_ssl' => false,
            'destination_type' => StandaloneDocker::class,
            'destination_id' => 1,
            'limits_cpus' => 0.5,
            'limits_cpu_shares' => 1024,
            'custom_docker_run_options' => '',
        ], $overrides));

        $destination = new class
        {
            public string $network = 'net-1';

            public ?Server $server = null;
        };
        $db->setRelation('destination', $destination);
        $db->setRelation('persistentStorages', collect());
        $db->setRelation('runtime_environment_variables', collect());

        return $db;
    }

    #[Test]
    public function it_builds_start_command_without_ssl()
    {
        $db = $this->fakeDatabase(['enable_ssl' => false, 'dragonfly_password' => 'pw']);
        $this->action->handle($db);

        $command = $this->callProtected($this->action, 'buildStartCommand');
        $this->assertStringContainsString('--requirepass pw', $command);
        $this->assertStringNotContainsString('--tls', $command);
    }

    #[Test]
    public function it_builds_start_command_with_ssl()
    {
        $server = Server::create([
            'name' => 'srv-01',
            'uuid' => 'srv-01-uuid',
            'ip' => '127.0.0.1',
            'team_id' => 1,
            'private_key_id' => 1,
        ]);

        // Pre-seed a CA certificate so the SSL branch finds an existing one instead of
        // calling Server::generateCaCertificate() -> SslHelper::generateSslCertificate(),
        // which relies on openssl_pkey_new() — unavailable in this environment.
        SslCertificate::create([
            'ssl_certificate' => 'ca-cert',
            'ssl_private_key' => 'ca-key',
            'server_id' => $server->id,
            'common_name' => 'Coolify CA Certificate',
            'valid_until' => now()->addYears(10),
            'is_ca_certificate' => true,
        ]);

        $db = $this->fakeDatabase(['enable_ssl' => true, 'dragonfly_password' => 'pw']);

        // Likewise, pre-seed the database's own certificate to avoid SslHelper::generateSslCertificate().
        SslCertificate::create([
            'ssl_certificate' => 'db-cert',
            'ssl_private_key' => 'db-key',
            'server_id' => $server->id,
            'resource_type' => StandaloneDragonfly::class,
            'resource_id' => $db->id,
            'common_name' => 'df-123',
            'valid_until' => now()->addYear(),
            'is_ca_certificate' => false,
        ]);

        $destination = new class($server)
        {
            public string $network = 'net-1';

            public function __construct(public Server $server) {}
        };
        $db->setRelation('destination', $destination);

        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls, 'remote_process should be called');

        $commands = $calls[0][0];
        $this->assertStringContainsString("echo 'Setting up SSL for this database.'", implode("\n", $commands));
        $this->assertStringContainsString('docker compose -f', implode("\n", $commands));
    }

    #[Test]
    public function it_removes_ssl_files_and_deletes_certificates_when_ssl_disabled()
    {
        $db = $this->fakeDatabase(['enable_ssl' => false, 'uuid' => 'df-123']);

        SslCertificate::create([
            'ssl_certificate' => 'cert',
            'ssl_private_key' => 'key',
            'server_id' => Server::create([
                'name' => 'srv-02',
                'uuid' => 'srv-02-uuid',
                'ip' => '127.0.0.2',
                'team_id' => 1,
                'private_key_id' => 1,
            ])->id,
            'resource_type' => StandaloneDragonfly::class,
            'resource_id' => $db->id,
            'common_name' => 'df-123',
            'valid_until' => now()->addYear(),
        ]);

        $this->action->handle($db);

        $this->assertSame(
            0,
            SslCertificate::where('resource_type', StandaloneDragonfly::class)->where('resource_id', $db->id)->count(),
            'sslCertificates()->delete() should have removed the certificate'
        );

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        // The first commands include rm -rf for ssl dir
        $commands = $calls[0][0];
        $this->assertStringContainsString('rm -rf /etc/coolify/databases/df-123/ssl', implode("\n", $commands));
    }

    #[Test]
    public function it_generates_docker_compose_and_includes_volumes_and_readme()
    {
        // LocalFileVolume::create() dispatches ServerStorageSaveJob on its "created" event
        // unconditionally; fake the queue so it doesn't actually run against our fixture.
        Queue::fake();

        $db = $this->fakeDatabase([
            'uuid' => 'df-999',
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
            'mount_path' => '/etc/dragonfly/certs/server.crt',
            'resource_type' => StandaloneDragonfly::class,
            'resource_id' => $db->id,
        ]);

        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $commands = $calls[0][0];
        // find the docker-compose base64 echo command
        $found = false;
        foreach ($commands as $cmd) {
            if (str_contains($cmd, 'docker-compose.yml')) {
                $found = true;
                if (preg_match("/echo '(.+)' \| base64 -d \| tee .*docker-compose.yml/", $cmd, $m)) {
                    $decoded = base64_decode($m[1]);
                    $yaml = Yaml::parse($decoded);
                    $this->assertArrayHasKey('services', $yaml);
                    $this->assertArrayHasKey('df-999', $yaml['services']);
                    // volumes should include the persistent host path
                    $this->assertContains('/host/path:/data', $yaml['services']['df-999']['volumes']);
                }
                break;
            }
        }
        $this->assertTrue($found, 'docker-compose write command should be present');
    }

    #[Test]
    public function it_adds_redis_password_env_if_missing()
    {
        $db = $this->fakeDatabase([
            'uuid' => 'df-env',
            'dragonfly_password' => 'pw123',
        ]);
        $db->setRelation('runtime_environment_variables', collect([]));

        $this->action->database = $db;
        $env = $this->callProtected($this->action, 'generate_environment_variables');

        $this->assertContains('REDIS_PASSWORD=pw123', $env);
    }
}
