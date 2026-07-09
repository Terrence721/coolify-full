<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ConnectProxyToNetworksJob;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Support\ServerChromeData;
use Exception;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class ServerDestinationsController extends Controller
{
    use AuthorizesRequests;

    public function index(string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        return Inertia::render('Server/Destinations', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'sidebar' => ServerChromeData::sidebar($server, 'main', 'destinations'),
            'isFunctional' => $server->isFunctional(),
            'canUpdate' => auth()->user()?->can('update', $server) ?? false,
            'canCreate' => auth()->user()?->can('createAnyResource') ?? false,
            'standaloneDockers' => $server->standaloneDockers->map(fn (StandaloneDocker $docker) => [
                'uuid' => $docker->uuid,
                'network' => $docker->network,
                'showUrl' => route('destination.show', ['destination_uuid' => $docker->uuid]),
            ]),
            'swarmDockers' => $server->swarmDockers->map(fn (SwarmDocker $docker) => [
                'uuid' => $docker->uuid,
                'network' => $docker->network,
                'showUrl' => route('destination.show', ['destination_uuid' => $docker->uuid]),
            ]),
            'servers' => Server::isUsable()->get()->map(fn (Server $s) => [
                'id' => $s->id,
                'name' => $s->name,
            ]),
            'scanUrl' => route('server.destinations.scan', ['server_uuid' => $server->uuid]),
            'addUrl' => route('server.destinations.add', ['server_uuid' => $server->uuid]),
            'createUrl' => route('server.destinations.create', ['server_uuid' => $server->uuid]),
        ]);
    }

    public function scan(string $server_uuid): JsonResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $alreadyAddedNetworks = $server->isSwarm() ? $server->swarmDockers : $server->standaloneDockers;

        $output = instant_remote_process(['docker network ls --format "{{json .}}"'], $server, false);
        $networks = format_docker_command_output_to_json($output)
            ->filter(fn ($network) => ! in_array($network['Name'], ['bridge', 'host', 'none']))
            ->filter(fn ($network) => ! $alreadyAddedNetworks->contains('network', $network['Name']))
            ->values();

        return response()->json([
            'networks' => $networks->map(fn ($network) => ['name' => $network['Name']])->all(),
        ]);
    }

    public function add(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $validated = Validator::make($request->all(), [
            'name' => ['required', 'string'],
        ])->validate();

        $name = $validated['name'];

        if ($server->isSwarm()) {
            $this->authorize('create', SwarmDocker::class);
            if ($server->swarmDockers()->where('network', $name)->exists()) {
                return back()->with('error', 'Network already added to this server.');
            }
            SwarmDocker::create([
                'name' => $server->name.'-'.$name,
                'network' => $name,
                'server_id' => $server->id,
            ]);
        } else {
            $this->authorize('create', StandaloneDocker::class);
            if ($server->standaloneDockers()->where('network', $name)->exists()) {
                return back()->with('error', 'Network already added to this server.');
            }
            StandaloneDocker::create([
                'name' => $server->name.'-'.$name,
                'network' => $name,
                'server_id' => $server->id,
            ]);
            ConnectProxyToNetworksJob::dispatchSync($server);
        }

        return back()->with('success', "Destination {$name} added.");
    }

    public function create(Request $request, string $server_uuid): RedirectResponse
    {
        $currentServer = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        $validated = Validator::make($request->all(), [
            'name' => ['required', 'string'],
            'network' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'],
            'server_id' => ['required', 'integer'],
        ])->validate();

        $selectedServer = Server::ownedByCurrentTeam()->whereKey($validated['server_id'])->firstOrFail();

        $this->authorize('create', StandaloneDocker::class);

        try {
            if ($selectedServer->standaloneDockers()->where('network', $validated['network'])->exists()) {
                throw new Exception('Network already added to this server.');
            }

            $docker = StandaloneDocker::create([
                'name' => $validated['name'],
                'network' => $validated['network'],
                'server_id' => $selectedServer->id,
            ]);
        } catch (Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('destination.show', ['destination_uuid' => $docker->uuid]);
    }
}
