<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PrivateKey;
use App\Models\Server;
use App\Support\ServerChromeData;
use Exception;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ServerPrivateKeyController extends Controller
{
    use AuthorizesRequests;

    public function index(string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $privateKeys = PrivateKey::ownedByCurrentTeam()->get()->where('is_git_related', false);

        return Inertia::render('Server/PrivateKey/Show', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'sidebar' => ServerChromeData::sidebar($server, 'main', 'private-key'),
            'currentPrivateKeyUuid' => data_get($server, 'privateKey.uuid'),
            'privateKeys' => $privateKeys->values()->map(fn (PrivateKey $key) => [
                'id' => $key->id,
                'uuid' => $key->uuid,
                'name' => $key->name,
                'description' => $key->description,
            ]),
            'canCreate' => Gate::forUser(auth()->user())->allows('createAnyResource'),
            'canUpdate' => Gate::forUser(auth()->user())->allows('update', $server),
            'setKeyUrl' => route('server.private-key.set', ['server_uuid' => $server->uuid]),
            'checkConnectionUrl' => route('server.private-key.check-connection', ['server_uuid' => $server->uuid]),
            'createKeyUrl' => route('security.private-key.store'),
            'generateKeyUrl' => route('security.private-key.generate'),
        ]);
    }

    public function setKey(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        $validated = $request->validate([
            'private_key_id' => ['required', 'integer'],
        ]);

        $ownedPrivateKey = PrivateKey::ownedByCurrentTeam()->find($validated['private_key_id']);
        if (is_null($ownedPrivateKey)) {
            return back()->with('error', 'You are not allowed to use this private key.');
        }

        $this->authorize('update', $server);

        try {
            DB::transaction(function () use ($server, $ownedPrivateKey) {
                $server->privateKey()->associate($ownedPrivateKey);
                $server->save();
                ['uptime' => $uptime, 'error' => $error] = $server->validateConnection(justCheckingNewKey: true);
                if (! $uptime) {
                    throw new Exception($error);
                }
            });
        } catch (Exception $e) {
            $server->refresh();
            $server->validateConnection();

            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Private key updated successfully.');
    }

    public function checkConnection(string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        ['uptime' => $uptime, 'error' => $error] = $server->validateConnection();

        if ($uptime) {
            return back()->with('success', 'Server is reachable.');
        }

        $sanitizedError = htmlspecialchars($error ?? '', ENT_QUOTES, 'UTF-8');

        return back()->with('error', "Server is not reachable. Error: {$sanitizedError}");
    }
}
