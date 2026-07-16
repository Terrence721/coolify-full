<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsTerminalCommand;
use App\Models\Server;
use Throwable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class TerminalController extends Controller
{
    use BuildsTerminalCommand;

    public function index(): Response
    {
        $servers = Server::isReachable()->get()->filter(fn (Server $server) => $server->isTerminalEnabled())->values();

        return Inertia::render('Terminal/Index', [
            'servers' => $servers->map(fn (Server $server) => [
                'uuid' => $server->uuid,
                'name' => $server->name,
            ])->all(),
            'containers' => Inertia::defer(fn () => $this->getAllActiveContainers($servers)),
            'terminalConfig' => [
                'protocol' => config('constants.terminal.protocol'),
                'host' => config('constants.terminal.host'),
                'port' => config('constants.terminal.port'),
            ],
            'connectUrl' => route('terminal.connect'),
        ]);
    }

    public function connect(Request $request): JsonResponse
    {
        $selectedUuid = (string) $request->input('selected_uuid');
        if ($selectedUuid === '' || $selectedUuid === 'default') {
            return response()->json(['error' => 'Please select a server or a container.'], 422);
        }

        $servers = Server::isReachable()->get()->filter(fn (Server $server) => $server->isTerminalEnabled())->values();
        $containers = $this->getAllActiveContainers($servers);
        $container = $containers->firstWhere('uuid', $selectedUuid);
        $isContainer = ! is_null($container);
        $identifier = $container['connection_name'] ?? $selectedUuid;
        $serverUuid = $container['server_uuid'] ?? $selectedUuid;

        $server = Server::ownedByCurrentTeam()->whereUuid($serverUuid)->first();
        if (! $server) {
            return response()->json(['error' => 'Server not found.'], 404);
        }

        $result = $this->resolveTerminalCommand($server, $isContainer ? $identifier : null);

        if (isset($result['error'])) {
            return response()->json(array_diff_key($result, ['status' => true]), $result['status']);
        }

        return response()->json(['command' => $result['command']]);
    }

    /**
     * @param  Collection<int, Server>  $servers
     * @return Collection<int, array<string, mixed>>
     */
    private function getAllActiveContainers($servers)
    {
        return $servers->flatMap(function (Server $server) {
            if (! $server->isFunctional()) {
                return [];
            }

            try {
                return $server->loadAllContainers()->map(function ($container) use ($server) {
                    $state = data_get_str($container, 'State')->lower();
                    if ($state->contains('running')) {
                        return [
                            'name' => data_get($container, 'Names'),
                            'connection_name' => data_get($container, 'Names'),
                            'uuid' => data_get($container, 'Names'),
                            'status' => data_get_str($container, 'State')->lower(),
                            'server_name' => $server->name,
                            'server_uuid' => $server->uuid,
                        ];
                    }

                    return null;
                })->filter();
            } catch (Throwable $exception) {
                report($exception);

                return [];
            }
        })->sortBy('name')->values();
    }
}
