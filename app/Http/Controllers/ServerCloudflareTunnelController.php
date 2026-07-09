<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Server\ConfigureCloudflared;
use App\Models\Server;
use App\Support\ServerChromeData;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class ServerCloudflareTunnelController extends Controller
{
    use AuthorizesRequests;

    public function index(string $server_uuid): Response|RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        if ($server->isLocalhost()) {
            return redirect()->route('server.show', ['server_uuid' => $server_uuid]);
        }

        return Inertia::render('Server/CloudflareTunnel', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'sidebar' => ServerChromeData::sidebar($server, 'main', 'cloudflare-tunnel'),
            'isCloudflareTunnelsEnabled' => (bool) $server->settings->is_cloudflare_tunnel,
            'isFunctional' => $server->isFunctional(),
            'hasPreviousIp' => (bool) $server->ip_previous,
            'canUpdate' => auth()->user()?->can('update', $server) ?? false,
            'toggleUrl' => route('server.cloudflare-tunnel.toggle', ['server_uuid' => $server->uuid]),
            'manualConfigUrl' => route('server.cloudflare-tunnel.manual-config', ['server_uuid' => $server->uuid]),
            'automatedConfigUrl' => route('server.cloudflare-tunnel.automated-config', ['server_uuid' => $server->uuid]),
        ]);
    }

    public function toggle(string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        remote_process(['docker rm -f coolify-cloudflared'], $server);

        $server->settings->is_cloudflare_tunnel = false;
        $server->settings->save();

        if ($server->ip_previous) {
            $server->update(['ip' => $server->ip_previous]);

            return back()->with('success', 'Cloudflare Tunnel disabled. Manually updated the server IP address to its previous IP address.');
        }

        return back()->with('warning', 'Cloudflare Tunnel disabled. Action required: Update the server IP address to its real IP address in the Advanced settings.');
    }

    public function manualConfig(string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $server->settings->is_cloudflare_tunnel = true;
        $server->settings->save();

        return back()->with('success', 'Cloudflare Tunnel enabled.');
    }

    public function automatedConfig(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $validated = Validator::make($request->all(), [
            'cloudflare_token' => ['required', 'string'],
            'ssh_domain' => ['required', 'string'],
        ])->validate();

        $sshDomain = $validated['ssh_domain'];
        if (str($sshDomain)->contains('https://')) {
            $sshDomain = str($sshDomain)->replace('https://', '')->replace('http://', '')->trim()->replace('/', '');
        }

        try {
            $activity = ConfigureCloudflared::run($server, $validated['cloudflare_token'], (string) $sshDomain);
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(['activityId' => $activity->id, 'activityContext' => 'cloudflare-tunnel']);
    }
}
