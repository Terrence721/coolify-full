<?php

declare(strict_types=1);

// Same technique as server_action_remote_process_overrides.php (see that file's own comment for
// why a plain global helper needs a same-namespace redeclaration to be interceptable in tests) -
// this one covers App\Actions\Service (StopService's instant_remote_process(), StartService's
// remote_process()).

namespace App\Actions\Service;

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
        RemoteProcessFake::$remoteProcessCalls[] = $args;

        // StartService::handle() declares an Activity return type - a real, unsaved instance
        // satisfies it without needing a real DB write or real SSH multiplexing.
        return new Activity;
    }
}
