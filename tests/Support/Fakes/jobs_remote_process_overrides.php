<?php

declare(strict_types=1);

// instant_remote_process_with_timeout() is a plain global helper function (not a
// container-resolved service), so app()->bind() cannot intercept it in tests. Declaring
// a same-named function inside App\Jobs lets PHP resolve CheckAndStartSentinelJob's
// unqualified call here first, before falling back to the real global implementation.
//
// This file declares a function (not a class), so it isn't PSR-4 autoloadable — require
// it explicitly (require_once) from any test whose subject lives in App\Jobs and calls
// this helper, then configure/reset Tests\Support\Fakes\JobsRemoteProcessFake per test.

namespace App\Jobs;

use Tests\Support\Fakes\JobsRemoteProcessFake;

if (! function_exists(__NAMESPACE__.'\instant_remote_process_with_timeout')) {
    function instant_remote_process_with_timeout(...$args): ?string
    {
        return JobsRemoteProcessFake::$output;
    }
}
