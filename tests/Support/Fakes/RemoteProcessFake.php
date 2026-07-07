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
        self::$containers = collect();
        self::$containersException = null;
        self::$instantRemoteProcessException = null;
        self::$instantRemoteProcessCalls = [];
    }
}
