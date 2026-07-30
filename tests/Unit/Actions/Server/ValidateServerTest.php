<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Server;

use App\Actions\Server\ValidateServer;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for a real bug found 2026-07-30 (code review, issue #70): when
 * validateDockerEngineVersion() failed (Docker installed, but below the minimum required
 * version), handle() showed "Docker Engine is not installed" - the same message used for the
 * genuinely-not-installed case a few lines above, which had already passed by this point. A
 * user on an old-but-installed Docker version was told to install Docker instead of upgrade it.
 */
class ValidateServerTest extends TestCase
{
    use RefreshDatabase;

    private function serverPassingUpToDockerVersionCheck(bool $dockerVersionOk): Server
    {
        $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
        $server = Mockery::mock($server)->makePartial();
        $server->shouldReceive('validateConnection')->andReturn(['uptime' => true, 'error' => null]);
        $server->shouldReceive('validateOS')->andReturn(str('ubuntu debian raspbian pop'));
        $server->shouldReceive('validatePrerequisites')->andReturn(['success' => true, 'missing' => [], 'found' => ['git', 'curl', 'jq']]);
        $server->shouldReceive('validateDockerEngine')->andReturn(true);
        $server->shouldReceive('validateDockerCompose')->andReturn(true);
        $server->shouldReceive('validateDockerEngineVersion')->andReturn($dockerVersionOk);

        return $server;
    }

    #[Test]
    public function an_outdated_but_installed_docker_engine_reports_the_real_problem_not_missing_installation(): void
    {
        $server = $this->serverPassingUpToDockerVersionCheck(dockerVersionOk: false);

        try {
            ValidateServer::run($server);
            $this->fail('Expected an exception for an outdated Docker Engine version.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('too old', $e->getMessage());
            $this->assertStringContainsString((string) config('constants.docker.minimum_required_version'), $e->getMessage());
            $this->assertStringNotContainsString('is not installed', $e->getMessage());
        }
    }

    #[Test]
    public function a_supported_docker_engine_version_passes(): void
    {
        $server = $this->serverPassingUpToDockerVersionCheck(dockerVersionOk: true);

        $this->assertSame('OK', ValidateServer::run($server));
    }
}
