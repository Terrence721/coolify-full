<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use App\Support\ValidationPatterns;

/**
 * Builds the SSH command a browser-side terminal session should run, either a plain login
 * shell on a server or a `docker exec` shell inside a specific container. Ported from
 * App\Livewire\Project\Shared\Terminal::sendTerminalCommand() (originally duplicated
 * near-identically in TerminalController::connect() for the standalone /terminal page);
 * extracted once ExecuteContainerCommandController needed the same logic for its
 * resource-scoped terminal.
 */
trait BuildsTerminalCommand
{
    /**
     * @return array{command: string}|array{error: string, status: int, reason?: string}
     */
    private function resolveTerminalCommand(Server $server, ?string $containerIdentifier): array
    {
        if (! $server->isTerminalEnabled() || $server->isForceDisabled()) {
            return ['error' => 'Terminal access is disabled on this server.', 'status' => 403];
        }

        $shellCommand = 'PATH=$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin && '.
                        'if [ -f ~/.profile ]; then . ~/.profile; fi && '.
                        'if [ -n "$SHELL" ] && [ -x "$SHELL" ]; then exec $SHELL; else sh; fi';

        if ($containerIdentifier === null) {
            $command = SshMultiplexingHelper::generateSshCommand(
                $server,
                $shellCommand,
                commandTimeout: (int) config('constants.terminal.command_timeout')
            );

            return ['command' => $command];
        }

        if (! ValidationPatterns::isValidContainerName($containerIdentifier)) {
            return ['error' => 'Invalid container identifier format.', 'status' => 422];
        }

        $status = getContainerStatus($server, $containerIdentifier);
        if ($status !== 'running') {
            return ['error' => 'Container is not running.', 'status' => 422];
        }

        if (! $this->checkShellAvailability($server, $containerIdentifier)) {
            return ['error' => 'No shell (bash/sh) is available in this container.', 'status' => 422, 'reason' => 'no-shell'];
        }

        $escapedIdentifier = escapeshellarg($containerIdentifier);
        $dockerCommand = "docker exec -it {$escapedIdentifier} sh -c '{$shellCommand}'";
        if ($server->isNonRoot()) {
            $dockerCommand = "sudo {$dockerCommand}";
        }

        $command = SshMultiplexingHelper::generateSshCommand(
            $server,
            $dockerCommand,
            commandTimeout: (int) config('constants.terminal.command_timeout')
        );

        return ['command' => $command];
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
