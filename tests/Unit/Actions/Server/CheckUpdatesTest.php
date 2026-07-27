<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Server;

use App\Actions\Server\CheckUpdates;
use App\Models\Server;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Fakes\RemoteProcessFake;
use Tests\TestCase;

require_once __DIR__.'/../../../Support/Fakes/server_action_remote_process_overrides.php';

/**
 * CheckUpdates::handle() maps a server's /etc/os-release ID to a package manager, then runs
 * that manager's real "list available updates" command over SSH. Real Alpine (apk) coverage
 * added 2026-07-27: an earlier pass found the OS map recognized `alpine` -> `apk` with no
 * `apk` case in the dispatch switch (an Alpine server silently reported package_manager: 'apk'
 * despite never actually running anything), briefly removed the mapping entirely rather than
 * implement it - reversed on request, with a real, working `apk` case added instead. The
 * captured `apk list --upgradable` output below is genuine: pulled from a real, deliberately
 * outdated `alpine:3.18.0` Docker container against the live Alpine package index, not
 * fabricated - Alpine's package-name/version split is genuinely ambiguous (both can contain
 * digits and hyphens, e.g. `libcrypto3-3.1.8-r0`, `busybox-binsh-1.36.1-r7`), so this exact
 * real output is what proves splitApkPackageAndVersion() actually works.
 */
class CheckUpdatesTest extends TestCase
{
    private const REAL_APK_LIST_UPGRADABLE_OUTPUT = <<<'OUTPUT'
        apk-tools-2.14.4-r0 x86_64 {apk-tools} (GPL-2.0-only) [upgradable from: apk-tools-2.14.0-r0]
        busybox-1.36.1-r7 x86_64 {busybox} (GPL-2.0-only) [upgradable from: busybox-1.36.0-r9]
        busybox-binsh-1.36.1-r7 x86_64 {busybox} (GPL-2.0-only) [upgradable from: busybox-binsh-1.36.0-r9]
        ca-certificates-bundle-20241121-r1 x86_64 {ca-certificates} (MPL-2.0 AND MIT) [upgradable from: ca-certificates-bundle-20230506-r0]
        libcrypto3-3.1.8-r0 x86_64 {openssl} (Apache-2.0) [upgradable from: libcrypto3-3.1.0-r4]
        libssl3-3.1.8-r0 x86_64 {openssl} (Apache-2.0) [upgradable from: libssl3-3.1.0-r4]
        musl-1.2.4-r3 x86_64 {musl} (MIT) [upgradable from: musl-1.2.4-r0]
        musl-utils-1.2.4-r3 x86_64 {musl} (MIT AND BSD-2-Clause AND GPL-2.0-or-later) [upgradable from: musl-utils-1.2.4-r0]
        ssl_client-1.36.1-r7 x86_64 {busybox} (GPL-2.0-only) [upgradable from: ssl_client-1.36.0-r9]
        OUTPUT;

    protected function setUp(): void
    {
        parent::setUp();

        RemoteProcessFake::reset();
    }

    private function mockReachableServer(): Server
    {
        $server = $this->createStub(Server::class);
        $server->method('serverStatus')->willReturn(true);

        return $server;
    }

    #[Test]
    public function real_alpine_output_parses_every_package_correctly_including_the_ambiguous_ones(): void
    {
        RemoteProcessFake::$outputQueue = [
            "ID=alpine\nVERSION_ID=3.18.0\n",
            '', // apk update -q - return value unused
            self::REAL_APK_LIST_UPGRADABLE_OUTPUT,
        ];

        $result = CheckUpdates::run($this->mockReachableServer());

        $this->assertSame('apk', $result['package_manager']);
        $this->assertSame('alpine', $result['osId']);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(9, $result['total_updates']);
        $this->assertArrayNotHasKey('unparsed_lines', $result, 'every real line should parse cleanly');

        $this->assertSame([
            'package' => 'libcrypto3',
            'new_version' => '3.1.8-r0',
            'current_version' => '3.1.0-r4',
            'architecture' => 'x86_64',
            'repository' => 'openssl',
        ], $result['updates'][4], 'a package whose name itself ends in a digit must not be misparsed');

        $this->assertSame([
            'package' => 'busybox-binsh',
            'new_version' => '1.36.1-r7',
            'current_version' => '1.36.0-r9',
            'architecture' => 'x86_64',
            'repository' => 'busybox',
        ], $result['updates'][2], 'a multi-hyphen package name must stay intact, not get split mid-name');

        $this->assertSame([
            'package' => 'ca-certificates-bundle',
            'new_version' => '20241121-r1',
            'current_version' => '20230506-r0',
            'architecture' => 'x86_64',
            'repository' => 'ca-certificates',
        ], $result['updates'][3], 'a date-shaped version (no dots) must still be recognized as version-like');
    }

    #[Test]
    public function dispatches_the_real_apk_commands_in_order(): void
    {
        RemoteProcessFake::$outputQueue = ["ID=alpine\n", '', ''];

        CheckUpdates::run($this->mockReachableServer());

        $dispatchedCommands = array_map(fn ($call) => $call[0][0], array_slice(RemoteProcessFake::$instantRemoteProcessCalls, 1));
        $this->assertSame(['apk update -q', 'LANG=C apk list --upgradable 2>/dev/null'], $dispatchedCommands);
    }

    #[Test]
    public function reports_up_to_date_with_zero_updates_when_apk_list_returns_nothing(): void
    {
        RemoteProcessFake::$outputQueue = ["ID=alpine\n", '', ''];

        $result = CheckUpdates::run($this->mockReachableServer());

        $this->assertSame(0, $result['total_updates']);
        $this->assertSame([], $result['updates']);
    }

    #[Test]
    public function an_unparseable_apk_line_is_collected_rather_than_silently_dropped(): void
    {
        RemoteProcessFake::$outputQueue = ["ID=alpine\n", '', "WARNING: some unrelated apk stderr line that leaked into stdout\n"];

        $result = CheckUpdates::run($this->mockReachableServer());

        $this->assertSame(0, $result['total_updates']);
        $this->assertSame(['WARNING: some unrelated apk stderr line that leaked into stdout'], $result['unparsed_lines']);
    }

    #[Test]
    public function real_arch_linux_dispatches_the_real_pacman_commands(): void
    {
        RemoteProcessFake::$output = "ID=arch\n";

        $result = CheckUpdates::run($this->mockReachableServer());

        $this->assertSame('pacman', $result['package_manager']);
        $this->assertSame('arch', $result['osId']);
        $dispatchedCommands = array_map(fn ($call) => $call[0][0], array_slice(RemoteProcessFake::$instantRemoteProcessCalls, 1));
        $this->assertSame(['pacman -Sy', 'pacman -Qu 2>/dev/null'], $dispatchedCommands);
    }

    #[Test]
    public function manjaro_is_normalized_to_the_arch_family_and_also_dispatches_pacman(): void
    {
        RemoteProcessFake::$output = "ID=manjaro\n";

        $result = CheckUpdates::run($this->mockReachableServer());

        $this->assertSame('pacman', $result['package_manager']);
    }

    #[Test]
    public function a_genuinely_unsupported_os_reports_a_clean_error_and_dispatches_nothing_further(): void
    {
        RemoteProcessFake::$output = "ID=freebsd\n";

        $result = CheckUpdates::run($this->mockReachableServer());

        $this->assertSame('Unsupported package manager', $result['error']);
        $this->assertNull($result['package_manager']);
        $this->assertCount(1, RemoteProcessFake::$instantRemoteProcessCalls, 'no update-check command should ever be dispatched for an unsupported package manager');
    }
}
