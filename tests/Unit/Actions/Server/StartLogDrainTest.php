<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Server;

use App\Actions\Server\StartLogDrain;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Fakes\RemoteProcessFake;
use Tests\TestCase;

require_once __DIR__.'/../../../Support/Fakes/server_action_remote_process_overrides.php';

/**
 * Regression coverage for a real bug found during the 2026-07-28 Server management smoke test
 * (issue #26): enabling the Custom Fluent Bit log drain with the (optional, not `required` in
 * the UI) Custom Parser Configuration field left empty crashed with a fatal
 * `base64_encode(): Argument #1 ($string) must be of type string, null given` - the 'custom'
 * branch passed `$server->settings->logdrain_custom_config_parser` (legitimately null) straight
 * into `base64_encode()` with no null guard, unlike every other branch which always builds a
 * real, non-null parsers string. Reproduced live: DB settings saved correctly, but the real
 * `coolify-log-drain` container never started, and the config files never even reached disk.
 */
class StartLogDrainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RemoteProcessFake::reset();
    }

    private function serverWithCustomLogDrain(?string $parser): Server
    {
        $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
        $server->settings->update([
            'is_logdrain_custom_enabled' => true,
            'logdrain_custom_config' => "[OUTPUT]\n    Name  stdout\n",
            'logdrain_custom_config_parser' => $parser,
        ]);

        return $server->refresh();
    }

    private function readmeContentFor(Server $server): string
    {
        RemoteProcessFake::reset();
        StartLogDrain::run($server);

        $commands = RemoteProcessFake::$instantRemoteProcessCalls[1][0];
        $readmeLine = collect($commands)->first(fn ($line) => str_contains($line, '/log-drains/README.md'));
        preg_match("/^echo '([^']*)' \| base64 -d/", $readmeLine, $matches);

        return base64_decode($matches[1]);
    }

    #[Test]
    public function the_readme_names_the_actual_configured_provider_for_each_log_drain_type(): void
    {
        // Real bug found 2026-07-30 (code review, issue #70): the README was hardcoded to
        // describe New Relic regardless of which drain type was actually enabled, so a server
        // configured for Highlight/Axiom/Custom got a real README.md on disk describing the
        // wrong provider entirely.
        $team = Team::factory()->create();

        $newrelic = Server::factory()->create(['team_id' => $team->id]);
        $newrelic->settings->update(['is_logdrain_newrelic_enabled' => true]);
        $this->assertStringContainsString('# New Relic Log Drain', $this->readmeContentFor($newrelic->refresh()));

        $highlight = Server::factory()->create(['team_id' => $team->id]);
        $highlight->settings->update(['is_logdrain_highlight_enabled' => true]);
        $readme = $this->readmeContentFor($highlight->refresh());
        $this->assertStringContainsString('# Highlight Log Drain', $readme);
        $this->assertStringNotContainsString('New Relic', $readme);

        $axiom = Server::factory()->create(['team_id' => $team->id]);
        $axiom->settings->update(['is_logdrain_axiom_enabled' => true]);
        $readme = $this->readmeContentFor($axiom->refresh());
        $this->assertStringContainsString('# Axiom Log Drain', $readme);
        $this->assertStringNotContainsString('New Relic', $readme);

        $custom = $this->serverWithCustomLogDrain('[PARSER]\n    Name   my_parser\n');
        $readme = $this->readmeContentFor($custom);
        $this->assertStringContainsString('# Custom Log Drain', $readme);
        $this->assertStringNotContainsString('New Relic', $readme);
    }

    #[Test]
    public function does_not_crash_when_the_custom_parser_config_is_null(): void
    {
        $server = $this->serverWithCustomLogDrain(null);

        StartLogDrain::run($server);

        // handle() always stops any existing log drain first (docker rm -f coolify-log-drain),
        // then makes one real call with the full config-write + docker compose up command list.
        $this->assertCount(2, RemoteProcessFake::$instantRemoteProcessCalls);
        $this->assertSame(['docker rm -f coolify-log-drain'], RemoteProcessFake::$instantRemoteProcessCalls[0][0]);
    }

    #[Test]
    public function an_empty_parser_config_is_written_as_a_genuinely_empty_file_not_the_string_null(): void
    {
        $server = $this->serverWithCustomLogDrain(null);

        StartLogDrain::run($server);

        $commands = RemoteProcessFake::$instantRemoteProcessCalls[1][0];
        $parserLine = collect($commands)->first(fn ($line) => str_contains($line, '/log-drains/parsers.conf'));

        $this->assertNotNull($parserLine);
        $this->assertStringStartsWith("echo '' | base64 -d | tee", $parserLine);
    }

    #[Test]
    public function a_real_parser_config_still_reaches_the_command_correctly_encoded(): void
    {
        $server = $this->serverWithCustomLogDrain("[PARSER]\n    Name   my_parser\n");

        StartLogDrain::run($server);

        $commands = RemoteProcessFake::$instantRemoteProcessCalls[1][0];
        $parserLine = collect($commands)->first(fn ($line) => str_contains($line, '/log-drains/parsers.conf'));
        $encoded = base64_encode("[PARSER]\n    Name   my_parser\n");

        $this->assertStringStartsWith("echo '{$encoded}' | base64 -d | tee", $parserLine);
    }

    #[Test]
    public function ends_with_the_real_docker_compose_up_command(): void
    {
        $server = $this->serverWithCustomLogDrain(null);

        StartLogDrain::run($server);

        $commands = RemoteProcessFake::$instantRemoteProcessCalls[1][0];
        $this->assertSame('cd '.config('constants.coolify.base_config_path').'/log-drains && docker compose up -d', end($commands));
    }
}
