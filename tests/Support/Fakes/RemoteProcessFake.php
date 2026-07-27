<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use Illuminate\Support\Collection;

/**
 * Shared, resettable state for instant_remote_process() / getCurrentApplicationContainerStatus()
 * overrides. Namespace-agnostic — reused by both the App\Actions\Application overrides
 * (tests/Support/Fakes/action_remote_process_overrides.php) and the App\Actions\Database
 * overrides (tests/Support/Fakes/database_action_overrides.php), since PHP's function
 * resolution requires a separate override declaration per calling namespace, but the
 * fake state backing them doesn't need to be duplicated.
 */
class RemoteProcessFake
{
    public static string $output = '';

    /**
     * Optional per-call outputs, consumed in order (shifted off the front) before falling
     * back to $output once exhausted. Needed by callers like CheckUpdates that make several
     * sequential instant_remote_process() calls expecting genuinely different output from
     * each one (an /etc/os-release read, then a real "list updates" command) - $output alone
     * can't express that, since every call returns the same single string.
     *
     * @var array<int, string>
     */
    public static array $outputQueue = [];

    public static Collection $containers;

    public static ?\Throwable $containersException = null;

    /**
     * Only thrown when instant_remote_process() is called with $throwError !== false
     * (its real 3rd argument) — matching the real helper, which only raises when the
     * caller hasn't explicitly opted out of error propagation.
     */
    public static ?\Throwable $instantRemoteProcessException = null;

    /** @var array<int, array<int, mixed>> Each entry is the argument list of one instant_remote_process() call. */
    public static array $instantRemoteProcessCalls = [];

    public static function reset(): void
    {
        self::$output = '';
        self::$outputQueue = [];
        self::$containers = collect();
        self::$containersException = null;
        self::$instantRemoteProcessException = null;
        self::$instantRemoteProcessCalls = [];
    }
}
