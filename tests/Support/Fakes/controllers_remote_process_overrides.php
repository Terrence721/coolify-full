<?php

declare(strict_types=1);

// Same technique as action_remote_process_overrides.php (see that file's own comment for why
// a plain global helper needs a same-namespace redeclaration to be interceptable in tests) -
// this one covers App\Http\Controllers (ProjectApplicationConfigurationController's
// rollbackLoadImages(), which calls instant_remote_process() directly rather than through
// an Action class).

namespace App\Http\Controllers;

use Tests\Support\Fakes\RemoteProcessFake;

if (! function_exists(__NAMESPACE__.'\instant_remote_process')) {
    function instant_remote_process(...$args)
    {
        RemoteProcessFake::$instantRemoteProcessCalls[] = $args;

        $throwError = $args[2] ?? true;
        if ($throwError !== false && RemoteProcessFake::$instantRemoteProcessException) {
            throw RemoteProcessFake::$instantRemoteProcessException;
        }

        if (! empty(RemoteProcessFake::$outputQueue)) {
            return array_shift(RemoteProcessFake::$outputQueue);
        }

        return RemoteProcessFake::$output;
    }
}
