<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Actions\Proxy\StopProxy;
use App\Jobs\RestartProxyJob;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

/**
 * Proxy start/stop/restart/check actions shared by every Server-scoped page via ServerNavbar.jsx
 * — ported from App\Livewire\Server\Navbar's restart()/checkProxy()/startProxy()/stop(). One
 * controller for all Server-scoped pages, mirroring how the original Navbar component was itself
 * shared chrome rather than duplicated per page.
 */
class ServerProxyActionsController extends Controller
{
    use AuthorizesRequests;

    public function restart(string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('manageProxy', $server);

        RestartProxyJob::dispatch($server);

        return back();
    }

    public function checkStatus(string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('manageProxy', $server);

        CheckProxy::run($server, true);

        return back();
    }

    public function start(string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('manageProxy', $server);

        CheckProxy::run($server, true);
        $activity = StartProxy::run($server, force: true);

        return back()->with(['activityId' => $activity->id, 'activityContext' => 'proxy']);
    }

    public function stop(string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('manageProxy', $server);

        StopProxy::dispatch($server, true);

        return back();
    }
}
