<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProxyTypes;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Rules\ValidServerIp;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ServerController extends Controller
{
    use AuthorizesRequests;

    public function index(): Response
    {
        $servers = Server::ownedByCurrentTeamCached();
        $privateKeys = PrivateKey::ownedByCurrentTeamCached();

        $limitReached = false;
        if (isCloud()) {
            $limitReached = Team::serverLimitReached();
        }

        return Inertia::render('Server/Index', [
            'servers' => $servers->map(fn (Server $server) => [
                'uuid' => $server->uuid,
                'name' => $server->name,
                'description' => $server->description,
                'isReachable' => (bool) $server->settings->is_reachable,
                'isUsable' => (bool) $server->settings->is_usable,
                'forceDisabled' => (bool) $server->settings->force_disabled,
                'showUrl' => route('server.show', ['server_uuid' => $server->uuid]),
            ]),
            'canCreate' => auth()->user()?->can('createAnyResource') ?? false,
            'limitReached' => $limitReached,
            'privateKeys' => $privateKeys->map(fn (PrivateKey $key) => [
                'id' => $key->id,
                'name' => $key->name,
            ]),
            'defaultPrivateKeyId' => $privateKeys->first()?->id,
            'defaultName' => generate_random_name(),
            'storeUrl' => route('server.store'),
        ]);
    }

    public function store(Request $request): RedirectResponse|SymfonyResponse
    {
        $validated = Validator::make($request->all(), [
            'private_key_id' => 'nullable|integer',
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'ip' => ['required', 'string', new ValidServerIp],
            'user' => ValidationPatterns::serverUsernameRules(),
            'port' => 'required|integer|between:1,65535',
            'is_build_server' => 'required|boolean',
        ], array_merge(ValidationPatterns::combinedMessages(), ValidationPatterns::serverUsernameMessages()))->validate();

        $this->authorize('create', Server::class);

        $foundServer = Server::whereIp($validated['ip'])->first();
        if ($foundServer) {
            if ($foundServer->team_id === currentTeam()->id) {
                return back()->with('error', 'A server with this IP/Domain already exists in your team.');
            }

            return back()->with('error', 'A server with this IP/Domain is already in use by another team.');
        }

        if (is_null($validated['private_key_id'])) {
            return back()->with('error', 'You must select a private key');
        }

        if (Team::serverLimitReached()) {
            return back()->with('error', 'You have reached the server limit for your team.');
        }

        $payload = [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'ip' => $validated['ip'],
            'user' => $validated['user'],
            'port' => $validated['port'],
            'team_id' => currentTeam()->id,
            'private_key_id' => $validated['private_key_id'],
        ];
        if ($validated['is_build_server']) {
            data_forget($payload, 'proxy');
        }

        $server = Server::create($payload);
        $server->proxy->set('status', 'exited');
        $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
        $server->save();
        $server->settings->is_build_server = $validated['is_build_server'];
        $server->settings->save();

        // Inertia::location() (not redirect()->route()) since server.show is still a plain
        // Livewire/Blade page, not an Inertia::render() response — Inertia's XHR-based post()
        // can't render a hand-off to a page it doesn't control, the same class of problem
        // AppLayout.jsx's Logout form works around by using a native <form> submit instead.
        // Inertia::location() sends a 409 with X-Inertia-Location, which the client-side
        // library recognizes and turns into a real, full-page browser navigation.
        return Inertia::location(route('server.show', ['server_uuid' => $server->uuid]));
    }
}
