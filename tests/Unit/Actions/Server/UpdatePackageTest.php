<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Server;

use App\Actions\Server\UpdatePackage;
use App\Models\Server;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Fakes\RemoteProcessFake;
use Tests\TestCase;

require_once __DIR__.'/../../../Support/Fakes/server_action_remote_process_overrides.php';

/**
 * UpdatePackage::handle() dispatches a package manager-specific command over SSH. Real bug
 * found 2026-07-30 (code review, issue #70): the switch had no `apk` case, even though
 * CheckUpdates.php (same file set) fully detects Alpine's `apk` and the Patches UI's own
 * tooltip advertises it as supported - an Alpine server correctly listed its pending updates,
 * then failed every update attempt with "OS not supported". This suite locks in the fix and
 * the other four package managers' dispatch, previously entirely untested.
 */
class UpdatePackageTest extends TestCase
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

    private function mockReachableServer(): Server
    {
        $server = $this->createStub(Server::class);
        $server->method('serverStatus')->willReturn(true);

        return $server;
    }

    #[Test]
    public function apk_upgrades_all_packages_with_the_real_alpine_command(): void
    {
        UpdatePackage::run($this->mockReachableServer(), osId: 'alpine', packageManager: 'apk', all: true);

        $this->assertSame(['apk upgrade'], RemoteProcessFake::$remoteProcessCalls[0][0]);
    }

    #[Test]
    public function apk_installs_a_single_package_with_the_real_alpine_command(): void
    {
        UpdatePackage::run($this->mockReachableServer(), osId: 'alpine', packageManager: 'apk', package: 'busybox');

        $this->assertSame(["apk add -u 'busybox'"], RemoteProcessFake::$remoteProcessCalls[0][0]);
    }

    #[Test]
    public function pacman_still_dispatches_its_own_real_command_unaffected_by_the_apk_fix(): void
    {
        UpdatePackage::run($this->mockReachableServer(), osId: 'arch', packageManager: 'pacman', all: true);

        $this->assertSame(['pacman -Syu --noconfirm'], RemoteProcessFake::$remoteProcessCalls[0][0]);
    }

    #[Test]
    public function a_genuinely_unsupported_package_manager_reports_a_clean_error(): void
    {
        $result = UpdatePackage::run($this->mockReachableServer(), osId: 'freebsd', packageManager: 'pkg', all: true);

        $this->assertSame(['error' => 'OS not supported'], $result);
        $this->assertCount(0, RemoteProcessFake::$remoteProcessCalls, 'no command should be dispatched for an unsupported package manager');
    }
}
