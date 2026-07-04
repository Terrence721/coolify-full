<?php

declare(strict_types=1);

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Activitylog\Contracts\Activity;

class RunCommand
{
    use AsAction;

    public function handle(Server $server, $command): Activity
    {
        return remote_process(command: [$command], server: $server, ignore_errors: true, type: 'command');
    }
}
