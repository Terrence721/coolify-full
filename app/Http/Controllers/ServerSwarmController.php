<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Server;
use App\Support\ServerChromeData;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class ServerSwarmController extends Controller
{
    use AuthorizesRequests;

    public function index(string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $settings = $server->settings;

        return Inertia::render('Server/Swarm', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'sidebar' => ServerChromeData::sidebar($server, 'main', 'swarm'),
            'deprecationNotice' => config('deprecations.swarm'),
            'isSwarmManager' => (bool) $settings->is_swarm_manager,
            'isSwarmWorker' => (bool) $settings->is_swarm_worker,
            'updateUrl' => route('server.swarm.update', ['server_uuid' => $server->uuid]),
        ]);
    }

    public function update(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $validated = Validator::make($request->all(), [
            'is_swarm_manager' => ['required', 'boolean'],
            'is_swarm_worker' => ['required', 'boolean'],
        ])->validate();

        $server->settings()->first()?->forceFill($validated)->save();

        return back()->with('success', 'Swarm settings updated.');
    }
}
