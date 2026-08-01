<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

/**
 * Backing state for jobs_remote_process_overrides.php's App\Jobs\instant_remote_process_with_timeout()
 * override. Separate from RemoteProcessFake (which types $output as non-nullable string) because the
 * real helper is ?string — callers like CheckAndStartSentinelJob must handle a genuine null return
 * (e.g. the target container doesn't exist).
 */
class JobsRemoteProcessFake
{
    public static ?string $output = '';

    /**
     * Optional per-call outputs, consumed in order (shifted off the front) before falling back to
     * $output once exhausted — same technique as RemoteProcessFake::$outputQueue, needed by callers
     * like CleanupHelperContainersJob that make a "list containers" call followed by several
     * different "remove container" calls in the same handle() run.
     *
     * @var array<int, string>
     */
    public static array $outputQueue = [];

    /** @var array<int, array<int, mixed>> Each entry is the argument list of one call. */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$output = '';
        self::$outputQueue = [];
        self::$calls = [];
    }
}
