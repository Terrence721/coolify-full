<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Services\ProxyDashboardCacheService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Builds the shared props consumed by ServerNavbar.jsx and ServerSidebar.jsx — the React
 * ports of Server\Navbar.php + resources/views/livewire/server/navbar.blade.php and the
 * resources/views/components/server/sidebar*.blade.php partials. Every converted
 * Server-scoped Inertia page calls into this so the chrome stays consistent without each
 * page's controller re-deriving the same flags.
 */
class ServerChromeData
{
    /**
     * @return array<string, mixed>
     */
    public static function navbar(Server $server): array
    {
        $user = auth()->user();
        $proxyStatus = $server->proxy->status ?? 'unknown';
        $traefikDashboardAvailable = false;
        if ($proxyStatus === 'running') {
            try {
                $traefikDashboardAvailable = ProxyDashboardCacheService::isTraefikDashboardAvailableFromCache($server);
            } catch (\Throwable) {
                $traefikDashboardAvailable = false;
            }
        }

        $outdatedInfo = $server->traefik_outdated_info;
        $hasTraefikOutdated = $server->proxyType() === ProxyTypes::TRAEFIK->value
            && ! empty($outdatedInfo) && isset($outdatedInfo['type']);

        return [
            'server' => [
                'uuid' => $server->uuid,
                'name' => $server->name,
                'id' => $server->id,
            ],
            'currentRouteName' => Route::currentRouteName(),
            'proxySet' => $server->proxySet(),
            'proxyStatus' => $proxyStatus,
            'proxyForceStop' => (bool) data_get($server, 'proxy.force_stop'),
            'serverIp' => $server->id === 0 ? base_ip() : $server->ip,
            'traefikDashboardAvailable' => $traefikDashboardAvailable,
            'hasTraefikOutdated' => $hasTraefikOutdated,
            'isSentinelEnabled' => $server->isSentinelEnabled(),
            'isSentinelLive' => $server->isSentinelLive(),
            'isSwarmWorker' => $server->isSwarmWorker(),
            'isBuildServer' => $server->isBuildServer(),
            'isFunctional' => $server->isFunctional(),
            'isSwarm' => $server->isSwarm(),
            'canAccessTerminal' => Gate::forUser($user)->allows('canAccessTerminal'),
            'canUpdate' => Gate::forUser($user)->allows('update', $server),
            'urls' => [
                'show' => route('server.show', ['server_uuid' => $server->uuid]),
                'proxy' => route('server.proxy', ['server_uuid' => $server->uuid]),
                'sentinel' => route('server.sentinel', ['server_uuid' => $server->uuid]),
                'resources' => route('server.resources', ['server_uuid' => $server->uuid]),
                'command' => route('server.command', ['server_uuid' => $server->uuid]),
                'securityPatches' => route('server.security.patches', ['server_uuid' => $server->uuid]),
                'restart' => route('server.proxy-actions.restart', ['server_uuid' => $server->uuid]),
                'stop' => route('server.proxy-actions.stop', ['server_uuid' => $server->uuid]),
                'start' => route('server.proxy-actions.start', ['server_uuid' => $server->uuid]),
                'checkStatus' => route('server.proxy-actions.check-status', ['server_uuid' => $server->uuid]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function sidebar(Server $server, string $variant, string $activeMenu): array
    {
        if ($variant === 'security') {
            return [
                'variant' => 'security',
                'activeMenu' => $activeMenu,
                'urls' => [
                    'patches' => route('server.security.patches', ['server_uuid' => $server->uuid]),
                    'terminalAccess' => route('server.security.terminal-access', ['server_uuid' => $server->uuid]),
                ],
            ];
        }

        return [
            'variant' => 'main',
            'activeMenu' => $activeMenu,
            'isFunctional' => $server->isFunctional(),
            'hasHetznerToken' => (bool) $server->hetzner_server_id,
            'isLocalhost' => $server->isLocalhost(),
            'isCloudflareTunnelEnabled' => (bool) data_get($server, 'settings.is_cloudflare_tunnel'),
            'isBuildServer' => $server->isBuildServer(),
            'urls' => [
                'general' => route('server.show', ['server_uuid' => $server->uuid]),
                'advanced' => route('server.advanced', ['server_uuid' => $server->uuid]),
                'privateKey' => route('server.private-key', ['server_uuid' => $server->uuid]),
                'cloudProviderToken' => route('server.cloud-provider-token', ['server_uuid' => $server->uuid]),
                'caCertificate' => route('server.ca-certificate', ['server_uuid' => $server->uuid]),
                'cloudflareTunnel' => route('server.cloudflare-tunnel', ['server_uuid' => $server->uuid]),
                'dockerCleanup' => route('server.docker-cleanup', ['server_uuid' => $server->uuid]),
                'destinations' => route('server.destinations', ['server_uuid' => $server->uuid]),
                'logDrains' => route('server.log-drains', ['server_uuid' => $server->uuid]),
                'metrics' => route('server.metrics', ['server_uuid' => $server->uuid]),
                'swarm' => route('server.swarm', ['server_uuid' => $server->uuid]),
                'delete' => route('server.delete', ['server_uuid' => $server->uuid]),
            ],
        ];
    }
}
