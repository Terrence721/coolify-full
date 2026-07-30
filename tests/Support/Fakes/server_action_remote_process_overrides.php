<?php

declare(strict_types=1);

// Same technique as action_remote_process_overrides.php (see that file's own comment for why
// a plain global helper needs a same-namespace redeclaration to be interceptable in tests) -
// this one covers App\Actions\Server (CheckUpdates' instant_remote_process(), UpdatePackage's
// remote_process()).

namespace App\Actions\Server;

use Spatie\Activitylog\Models\Activity;
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

if (! function_exists(__NAMESPACE__.'\remote_process')) {
    function remote_process(...$args)
    {
        if (! RemoteProcessFake::$remoteProcessActive) {
            return \remote_process(...$args);
        }

        RemoteProcessFake::$remoteProcessCalls[] = $args;

        // Callers in this namespace declare a mix of Activity|array (UpdatePackage) and a bare
        // Activity (InstallPrerequisites, ConfigureCloudflared) - a real, unsaved Activity model
        // instance satisfies both without needing a real DB write.
        return new Activity;
    }
}
