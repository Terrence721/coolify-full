<?php

declare(strict_types=1);

namespace App\Actions\Proxy;

use App\Events\ProxyStatusChanged;
use App\Events\ProxyStatusChangedUI;
use App\Models\Server;
use App\Services\ProxyDashboardCacheService;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class StopProxy
{
    use AsAction;

    public function handle(Server $server, bool $forceStop = true, int $timeout = 30, bool $restarting = false): mixed
    {
        try {
            $containerName = $server->isSwarm() ? 'coolify-proxy_traefik' : 'coolify-proxy';
            $server->proxy->status = 'stopping';
            $server->save();

            if (! $restarting) {
                ProxyStatusChangedUI::dispatch($server->team_id);
            }

            instant_remote_process(command: [
                "docker stop -t=$timeout $containerName 2>/dev/null || true",
                "docker rm -f $containerName 2>/dev/null || true",
                '# Wait for container to be fully removed',
                'for i in {1..10}; do',
                "    if ! docker ps -a --format \"{{.Names}}\" | grep -q \"^$containerName$\"; then",
                '        break',
                '    fi',
                '    sleep 1',
                'done',
            ], server: $server, throwError: false);

            $server->proxy->force_stop = $forceStop;
            $server->proxy->status = 'exited';
            $server->save();

            return null;
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in handle().', ['error' => $e->getMessage()]);

            return handleError($e);
        } finally {
            ProxyDashboardCacheService::clearCache($server);

            if (! $restarting) {
                ProxyStatusChanged::dispatch($server->id);
            }
        }
    }
}
