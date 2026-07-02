<?php

namespace App\Actions\Server;

use Lorisleiva\Actions\Concerns\AsAction;

class RunCommand
{
    use AsAction;

    public function handle($server, $command)
    {
        return remote_process(command: [$command], server: $server, ignore_errors: true, type: 'command');
    }
}
