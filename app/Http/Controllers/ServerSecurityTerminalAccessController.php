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

class ServerSecurityTerminalAccessController extends Controller
{
    use AuthorizesRequests;

    public function index(string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        return Inertia::render('Server/Security/TerminalAccess', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'sidebar' => ServerChromeData::sidebar($server, 'security', 'terminal-access'),
            'serverName' => $server->name,
            'isTerminalEnabled' => (bool) $server->settings->is_terminal_enabled,
            'isAdmin' => (bool) auth()->user()?->isAdmin(),
            'toggleUrl' => route('server.security.terminal-access.toggle', ['server_uuid' => $server->uuid]),
        ]);
    }

    public function toggle(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        if (! auth()->user()->isAdmin()) {
            return back()->with('error', 'Only team administrators and owners can modify terminal access.');
        }

        $validated = Validator::make($request->all(), [
            'password' => ['required', 'string'],
        ])->validate();

        if (! verifyPasswordConfirmation($validated['password'])) {
            return back()->with('error', 'The provided password is incorrect.');
        }

        $server->settings->is_terminal_enabled = ! $server->settings->is_terminal_enabled;
        $server->settings->save();

        $status = $server->settings->is_terminal_enabled ? 'enabled' : 'disabled';

        return back()->with('success', "Terminal access has been {$status}.");
    }
}
