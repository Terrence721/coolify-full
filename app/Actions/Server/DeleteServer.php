<?php

declare(strict_types=1);

namespace App\Actions\Server;

use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteServer
{
    use AsAction;

    public function handle(int $serverId): void
    {
        $server = Server::withTrashed()->find($serverId);

        ray($server ? 'Deleting server from Coolify' : 'Server already deleted from Coolify, skipping Coolify deletion');

        // If server is already deleted from Coolify, skip this part
        if (! $server) {
            return; // Server already force deleted from Coolify
        }

        ray('force deleting server from Coolify', ['server_id' => $server->id]);

        try {
            $server->forceDelete();
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in handle().', ['error' => $e->getMessage()]);

            ray('Failed to force delete server from Coolify', [
                'error' => $e->getMessage(),
                'server_id' => $server->id,
            ]);
            logger()->error('Failed to force delete server from Coolify', [
                'error' => $e->getMessage(),
                'server_id' => $server->id,
            ]);
        }
    }
}
