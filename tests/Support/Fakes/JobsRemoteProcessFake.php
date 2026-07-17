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

    public static function reset(): void
    {
        self::$output = '';
    }
}
