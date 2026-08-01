<?php

declare(strict_types=1);

// Same namespace-shadowing trick as action_remote_process_overrides.php (see its own
// docblock), for App\Actions\Shared\ComplexStatusCheck's instant_remote_process() call.
//
// Require explicitly (require_once) from any test whose subject lives in App\Actions\Shared
// and calls instant_remote_process(), then configure/reset Tests\Support\Fakes\RemoteProcessFake
// per test.

namespace App\Actions\Shared;

use Tests\Support\Fakes\RemoteProcessFake;

if (! function_exists(__NAMESPACE__.'\instant_remote_process')) {
    function instant_remote_process(...$args)
    {
        RemoteProcessFake::$instantRemoteProcessCalls[] = $args;

        $throwError = $args[2] ?? true;
        if ($throwError !== false && RemoteProcessFake::$instantRemoteProcessException) {
            throw RemoteProcessFake::$instantRemoteProcessException;
        }

        return RemoteProcessFake::$output;
    }
}
