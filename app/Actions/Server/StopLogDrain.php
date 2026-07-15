<?php

declare(strict_types=1);

namespace App\Actions\Server;

use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class StopLogDrain
{
    use AsAction;

    public function handle(Server $server): mixed
    {
        try {
            return instant_remote_process(['docker rm -f coolify-log-drain'], $server, false);
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in handle().', ['error' => $e->getMessage()]);

            return handleError($e);
        }
    }
}
