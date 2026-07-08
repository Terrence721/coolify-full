<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Fakes\DatabaseActionFake;
use Tests\Support\InteractsWithDatabaseActions;
use Tests\TestCase;

final class StartClickhouseTest extends TestCase
{
    use InteractsWithDatabaseActions;
    use RefreshDatabase;

    #[Test]
    public function it_generates_correct_commands_and_invokes_remote_process()
    {
        $db = $this->fakeDatabase(['uuid' => 'clickhouse-999']);

        $this->action->handle($db);

        $calls = DatabaseActionFake::$remoteProcessCalls;
        $this->assertNotEmpty($calls);

        $commands = $calls[0][0];
        $this->assertSame("echo 'Starting database.'", $commands[0]);
        $this->assertSame('mkdir -p /etc/coolify/databases/clickhouse-999', $commands[1]);

        $this->assertStringContainsString('docker-compose.yml', $commands[2]);
        $this->assertStringContainsString('README.md', $commands[3]);
        $this->assertStringContainsString('Pulling clickhouse:latest image.', $commands[4]);
        $this->assertStringContainsString('docker compose -f', $commands[5]);
        $this->assertStringContainsString('docker stop -t 10 clickhouse-999', $commands[6]);
        $this->assertStringContainsString('docker rm -f clickhouse-999', $commands[7]);
        $this->assertStringContainsString('docker compose -f', $commands[8]);
        $this->assertSame("echo 'Database started.'", $commands[9]);
    }

    #[Test]
    public function it_includes_environment_variables_correctly()
    {
        $db = $this->fakeDatabase(['uuid' => 'clickhouse-env']);
        $db->setRelation('runtime_environment_variables', collect([
            (object) ['key' => 'FOO', 'real_value' => 'BAR'],
        ]));

        $this->action->handle($db);

        $commands = DatabaseActionFake::$remoteProcessCalls[0][0];
        preg_match("/echo '(.+)'/", $commands[2], $matches);
        $yaml = base64_decode($matches[1]);
        $env = Yaml::parse($yaml)['services']['clickhouse-env']['environment'];

        $this->assertContains('FOO=BAR', $env);
        $this->assertContains('CLICKHOUSE_USER=admin', $env);
        $this->assertContains('CLICKHOUSE_PASSWORD=secret', $env);
        $this->assertContains('CLICKHOUSE_DB=default', $env);
    }

    #[Test]
    public function it_includes_persistent_volumes_correctly()
    {
        $db = $this->fakeDatabase(['uuid' => 'clickhouse-vol']);
        $db->setRelation('persistentStorages', collect([
            (object) [
                'host_path' => '/data/clickhouse',
                'mount_path' => '/var/lib/clickhouse',
                'name' => 'ch-volume',
            ],
        ]));

        $this->action->handle($db);

        $commands = DatabaseActionFake::$remoteProcessCalls[0][0];
        preg_match("/echo '(.+)'/", $commands[2], $matches);
        $yaml = base64_decode($matches[1]);
        $volumes = Yaml::parse($yaml)['services']['clickhouse-vol']['volumes'];

        $this->assertSame(['/data/clickhouse:/var/lib/clickhouse'], $volumes);
    }

    #[Test]
    public function it_includes_ports_correctly()
    {
        $db = $this->fakeDatabase(['uuid' => 'clickhouse-ports', 'ports_mappings' => '9000:9000']);

        $this->action->handle($db);

        $commands = DatabaseActionFake::$remoteProcessCalls[0][0];
        preg_match("/echo '(.+)'/", $commands[2], $matches);
        $yaml = base64_decode($matches[1]);
        $ports = Yaml::parse($yaml)['services']['clickhouse-ports']['ports'];

        $this->assertSame(['9000:9000'], $ports);
    }

    #[Test]
    public function it_unsets_healthcheck_when_disabled()
    {
        $db = $this->fakeDatabase(['uuid' => 'clickhouse-health']);
        // No health_check_enabled column exists on standalone_clickhouses; HasDatabaseHealthCheck
        // reads it as a plain dynamic attribute, so setting it directly is sufficient.
        $db->health_check_enabled = false;

        $this->action->handle($db);

        $commands = DatabaseActionFake::$remoteProcessCalls[0][0];
        preg_match("/echo '(.+)'/", $commands[2], $matches);
        $yaml = base64_decode($matches[1]);
        $parsed = Yaml::parse($yaml);

        $this->assertArrayHasKey('clickhouse-health', $parsed['services']);
        $this->assertArrayNotHasKey('healthcheck', $parsed['services']['clickhouse-health']);
    }

    #[Test]
    public function it_adds_logging_when_server_and_db_enable_log_drain()
    {
        $server = $this->createTestServer();
        $server->settings->update(['is_logdrain_custom_enabled' => true]);

        $db = $this->fakeDatabase(['uuid' => 'clickhouse-log', 'is_log_drain_enabled' => true]);
        $db->setRelation('destination', $this->destinationWithServer($server));

        $this->action->handle($db);

        $commands = DatabaseActionFake::$remoteProcessCalls[0][0];
        preg_match("/echo '(.+)'/", $commands[2], $matches);
        $yaml = base64_decode($matches[1]);
        $parsed = Yaml::parse($yaml);

        $this->assertArrayHasKey('logging', $parsed['services']['clickhouse-log']);
    }
}
