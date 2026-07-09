<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use App\Support\ValidationPatterns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TerminalController extends Controller
{
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
        if (! $server->isTerminalEnabled() || $server->isForceDisabled()) {
            return response()->json(['error' => 'Terminal access is disabled on this server.'], 403);
        }

        if ($isContainer) {
            if (! ValidationPatterns::isValidContainerName($identifier)) {
                return response()->json(['error' => 'Invalid container identifier format.'], 422);
            }

            $status = getContainerStatus($server, $identifier);
            if ($status !== 'running') {
                return response()->json(['error' => 'Container is not running.'], 422);
            }

            if (! $this->checkShellAvailability($server, $identifier)) {
                return response()->json(['error' => 'No shell (bash/sh) is available in this container.', 'reason' => 'no-shell'], 422);
            }

            $escapedIdentifier = escapeshellarg($identifier);
            $shellCommand = 'PATH=$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin && '.
                            'if [ -f ~/.profile ]; then . ~/.profile; fi && '.
                            'if [ -n "$SHELL" ] && [ -x "$SHELL" ]; then exec $SHELL; else sh; fi';

            $dockerCommand = "docker exec -it {$escapedIdentifier} sh -c '{$shellCommand}'";
            if ($server->isNonRoot()) {
                $dockerCommand = "sudo {$dockerCommand}";
            }

            $command = SshMultiplexingHelper::generateSshCommand(
                $server,
                $dockerCommand,
                commandTimeout: (int) config('constants.terminal.command_timeout')
            );
        } else {
            $shellCommand = 'PATH=$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin && '.
                            'if [ -f ~/.profile ]; then . ~/.profile; fi && '.
                            'if [ -n "$SHELL" ] && [ -x "$SHELL" ]; then exec $SHELL; else sh; fi';
            $command = SshMultiplexingHelper::generateSshCommand(
                $server,
                $shellCommand,
                commandTimeout: (int) config('constants.terminal.command_timeout')
            );
        }

        return response()->json(['command' => $command]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function getAllActiveContainers($servers)
    {
        return collect($servers)->flatMap(function (Server $server) {
            if (! $server->isFunctional()) {
                return [];
            }

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
        })->sortBy('name')->values();
    }

    private function checkShellAvailability(Server $server, string $container): bool
    {
        $escapedContainer = escapeshellarg($container);
        try {
            instant_remote_process([
                "docker exec {$escapedContainer} bash -c 'exit 0' 2>/dev/null || ".
                "docker exec {$escapedContainer} sh -c 'exit 0' 2>/dev/null",
            ], $server);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
