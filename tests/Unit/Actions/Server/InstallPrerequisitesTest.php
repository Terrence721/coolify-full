<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Server;

use App\Actions\Server\InstallPrerequisites;
use App\Models\Server;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Fakes\RemoteProcessFake;
use Tests\TestCase;

require_once __DIR__.'/../../../Support/Fakes/server_action_remote_process_overrides.php';

/**
 * Regression coverage for a real bug found 2026-07-30 (code review, issue #70): `alpine` is one
 * of the 5 entries in SUPPORTED_OS and Server::validateOS() correctly recognizes it, but the
 * OS if/elseif chain here had no `alpine` branch at all - a fresh Alpine server fell into the
 * `else` and threw "Unsupported OS type for prerequisites installation", contradicting
 * validateOS()'s own answer one step earlier. Could never be automatically onboarded.
 */
class InstallPrerequisitesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RemoteProcessFake::reset();
        RemoteProcessFake::$remoteProcessActive = true;
    }

    protected function tearDown(): void
    {
        RemoteProcessFake::$remoteProcessActive = false;

        parent::tearDown();
    }

    private function serverWithOs(string $osType): Server
    {
        $server = $this->createStub(Server::class);
        $server->method('validateOS')->willReturn(str($osType));

        return $server;
    }

    #[Test]
    public function alpine_installs_prerequisites_with_the_real_apk_commands(): void
    {
        InstallPrerequisites::run($this->serverWithOs('alpine'));

        $command = RemoteProcessFake::$remoteProcessCalls[0][0]->all();

        $this->assertContains('apk update', $command);
        $this->assertContains('command -v curl >/dev/null || apk add curl', $command);
        $this->assertContains('command -v wget >/dev/null || apk add wget', $command);
        $this->assertContains('command -v git >/dev/null || apk add git', $command);
        $this->assertContains('command -v jq >/dev/null || apk add jq', $command);
    }

    #[Test]
    public function debian_still_dispatches_its_own_real_commands_unaffected_by_the_alpine_fix(): void
    {
        InstallPrerequisites::run($this->serverWithOs('ubuntu debian raspbian pop'));

        $command = RemoteProcessFake::$remoteProcessCalls[0][0]->all();

        $this->assertContains('apt-get update -y', $command);
        $this->assertContains('command -v curl >/dev/null || apt install -y curl', $command);
    }

    #[Test]
    public function a_genuinely_unsupported_os_still_throws(): void
    {
        $server = $this->createStub(Server::class);
        $server->method('validateOS')->willReturn(false);

        $this->expectExceptionMessage('Server OS type is not supported for automated installation. Please install prerequisites manually.');

        InstallPrerequisites::run($server);
    }
}
