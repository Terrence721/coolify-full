<?php

declare(strict_types=1);

// Same technique as action_remote_process_overrides.php (see that file's own comment for why
// a plain global helper needs a same-namespace redeclaration to be interceptable in tests) -
// this one covers App\Actions\Server (CheckUpdates' instant_remote_process(), UpdatePackage's
// remote_process()).

namespace App\Actions\Server;

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

        // UpdatePackage::handle() declares Activity|array - an array satisfies that without
        // needing a real Activity model instance, which this suite has no use for.
        return ['output' => RemoteProcessFake::$output];
    }
}
