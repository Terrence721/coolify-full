<?php

declare(strict_types=1);

// instant_remote_process() and getCurrentApplicationContainerStatus() are plain global
// helper functions (not container-resolved services), so app()->bind() cannot intercept
// them in tests. Declaring same-named functions inside the callee's namespace lets PHP
// resolve these unqualified calls here first, before falling back to the real global
// implementation, since PHP checks the current namespace before the global one.
//
// This file declares functions (not a class), so it isn't PSR-4 autoloadable — require
// it explicitly (require_once) from any test whose subject lives in App\Actions\Application
// and calls these helpers, then configure/reset Tests\Support\Fakes\RemoteProcessFake
// per test.

namespace App\Actions\Application;

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

if (! function_exists(__NAMESPACE__.'\getCurrentApplicationContainerStatus')) {
    function getCurrentApplicationContainerStatus(...$args)
    {
        if (RemoteProcessFake::$containersException) {
            throw RemoteProcessFake::$containersException;
        }

        return RemoteProcessFake::$containers;
    }
}
