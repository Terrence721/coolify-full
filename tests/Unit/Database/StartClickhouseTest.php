<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Database;

use App\Actions\Database\StartClickhouse;
use App\Models\Server;
use App\Models\StandaloneClickhouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Fakes\DatabaseActionFake;
use Tests\TestCase;

class StartClickhouseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DatabaseActionFake::reset();
    }

    private function fakeDatabase(): StandaloneClickhouse
    {
        $server = $this->createStub(Server::class);
        $server->method('isLogDrainEnabled')->willReturn(false);

        $destination = new class($server)
        {
            public string $network;

            public function __construct(public $server)
            {
                $this->network = 'net-clickhouse';
            }
        };

        $db = new StandaloneClickhouse;
        $db->uuid = 'clickhouse-123';
        $db->name = 'ClickhouseDB';
        $db->image = 'clickhouse:latest';
        $db->setRelation('destination', $destination);

        // Limits
        $db->limits_memory = '512m';
        $db->limits_memory_swap = '1g';
        $db->limits_memory_swappiness = 60;
        $db->limits_memory_reservation = '256m';
        $db->limits_cpus = 1.5;
        $db->limits_cpu_shares = 1024;
        $db->limits_cpuset = null;

        // Ports (ports_mappings_array is a get-only accessor derived from this column)
        $db->ports_mappings = '9000:9000';

        // Persistent storages
        $db->persistentStorages = collect([
            (object) [
                'host_path' => '/data/clickhouse',
                'mount_path' => '/var/lib/clickhouse',
                'name' => 'ch-volume',
            ],
        ]);

        // Runtime env vars
        $db->runtime_environment_variables = collect([
            (object) ['key' => 'FOO', 'real_value' => 'BAR'],
        ]);

        // Admin credentials
        $db->clickhouse_admin_user = 'admin';
        $db->clickhouse_admin_password = 'secret';
        $db->clickhouse_db = 'default';

        // Custom docker run options
        $db->custom_docker_run_options = null;

        // BaseModel::image() (and similar sanitized accessors) read via getRawOriginal(),
        // which only reflects $this->original — populated once at construction, before
        // any of the attributes above were set. Sync it now to mimic a model freshly
        // hydrated from the database.
        $db->syncOriginal();

        return $db;
    }

    #[Test]
    public function it_generates_correct_commands_and_invokes_remote_process()
    {
        $db = $this->fakeDatabase();

        $action = new StartClickhouse;
        $result = $action->handle($db);

        $this->assertSame('OK', $result);

        $captured = DatabaseActionFake::$remoteProcessCalls[0];
        $commands = $captured[0];

        // Validate commands
        $this->assertNotEmpty($commands);
        $this->assertSame('DatabaseStatusChanged', $captured['callEventOnFinish'] ?? $captured[2] ?? null);

        // Validate first commands
        $this->assertSame("echo 'Starting database.'", $commands[0]);
        $this->assertSame('mkdir -p /etc/coolify/databases/clickhouse-123', $commands[1]);

        // Validate docker-compose YAML command
        $composeCommand = $commands[2];
        $this->assertStringContainsString('docker-compose.yml', $composeCommand);

        // Validate README command
        $this->assertStringContainsString('README.md', $commands[3]);

        // Validate pull/start commands
        $this->assertStringContainsString('Pulling clickhouse:latest image.', $commands[4]);
        $this->assertStringContainsString('docker compose -f', $commands[5]);
        $this->assertStringContainsString('docker stop -t 10 clickhouse-123', $commands[6]);
        $this->assertStringContainsString('docker rm -f clickhouse-123', $commands[7]);
        $this->assertStringContainsString('docker compose -f', $commands[8]);
        $this->assertSame("echo 'Database started.'", $commands[9]);
    }

    #[Test]
    public function it_includes_environment_variables_correctly()
    {
        $db = $this->fakeDatabase();

        $action = new StartClickhouse;
        $action->handle($db);

        $commands = DatabaseActionFake::$remoteProcessCalls[0][0];

        // Extract YAML from base64
        $yamlCommand = $commands[2];
        preg_match("/echo '(.+)'/", $yamlCommand, $matches);
        $yaml = base64_decode($matches[1]);

        $parsed = Yaml::parse($yaml);

        $env = $parsed['services'][$db->uuid]['environment'];

        $this->assertContains('FOO=BAR', $env);
        $this->assertContains('CLICKHOUSE_USER=admin', $env);
        $this->assertContains('CLICKHOUSE_PASSWORD=secret', $env);
        $this->assertContains('CLICKHOUSE_DB=default', $env);
    }

    #[Test]
    public function it_includes_persistent_volumes_correctly()
    {
        $db = $this->fakeDatabase();

        $action = new StartClickhouse;
        $action->handle($db);

        $commands = DatabaseActionFake::$remoteProcessCalls[0][0];

        preg_match("/echo '(.+)'/", $commands[2], $matches);
        $yaml = base64_decode($matches[1]);
        $parsed = Yaml::parse($yaml);

        $volumes = $parsed['services'][$db->uuid]['volumes'];

        $this->assertSame(['/data/clickhouse:/var/lib/clickhouse'], $volumes);
    }

    #[Test]
    public function it_includes_ports_correctly()
    {
        $db = $this->fakeDatabase();

        $action = new StartClickhouse;
        $action->handle($db);

        $commands = DatabaseActionFake::$remoteProcessCalls[0][0];

        preg_match("/echo '(.+)'/", $commands[2], $matches);
        $yaml = base64_decode($matches[1]);
        $parsed = Yaml::parse($yaml);

        $ports = $parsed['services'][$db->uuid]['ports'];

        $this->assertSame(['9000:9000'], $ports);
    }
}
