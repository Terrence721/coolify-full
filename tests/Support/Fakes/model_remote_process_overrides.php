<?php

declare(strict_types=1);

// instant_remote_process() is a plain global helper function (not a container-resolved
// service), so app()->bind() cannot intercept it in tests. Declaring a same-named function
// inside the callee's namespace lets PHP resolve unqualified calls here first, before
// falling back to the real global implementation — same trick as
// action_remote_process_overrides.php (App\Actions\Application) and
// database_action_overrides.php (App\Actions\Database), needed here because
// LocalFileVolume::deleteStorageOnServer() (App\Models) calls it directly.
//
// This file declares a function (not a class), so it isn't PSR-4 autoloadable — it's loaded
// via composer.json's autoload-dev.files instead (alongside its two siblings), active for
// every test run. Configure/reset Tests\Support\Fakes\RemoteProcessFake per test.

namespace App\Models;

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
