<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Actions\Server\CleanupDocker;
use App\Events\ServiceStatusChanged;
use App\Models\Server;
use App\Models\StandaloneDatabaseInstance;
use Lorisleiva\Actions\Concerns\AsAction;

class StopDatabase
{
    use AsAction;

    public function handle(StandaloneDatabaseInstance $database, bool $dockerCleanup = true): string
    {
        try {
            $destination = data_get($database, 'destination');
            // The destination's server may have been soft-deleted already (e.g. mid-flight during
            // a "delete server + force-delete resources" request) - the default belongsTo query
            // excludes trashed rows, so fall back to a trashed-inclusive lookup rather than
            // silently losing the ability to reach and stop the real remote container.
            $server = data_get($destination, 'server') ?? $destination?->server()->withTrashed()->first();
            if (! $server || ! $server->isFunctional()) {
                return 'Server is not functional';
            }

            $uuid = (string) data_get($database, 'uuid');
            $this->stopContainer($server, $uuid, 30);

            // Reset restart tracking when database is manually stopped
            $database->update([
                'status' => 'exited',
                'restart_count' => 0,
                'last_restart_at' => null,
                'last_restart_type' => null,
            ]);

            if ($dockerCleanup) {
                CleanupDocker::dispatch($server, false, false);
            }

            if ((bool) data_get($database, 'is_public', false)) {
                StopDatabaseProxy::run($database);
            }

            return 'Database stopped successfully';
        } catch (\Exception $e) {
            return 'Database stop failed: '.$e->getMessage();
        } finally {
            $teamId = data_get($database, 'environment.project.team.id');
            ServiceStatusChanged::dispatch($teamId);
        }

    }

    private function stopContainer(Server $server, string $containerName, int $timeout = 30): void
    {
        instant_remote_process(command: [
            "docker stop -t $timeout $containerName",
            "docker rm -f $containerName",
        ], server: $server, throwError: false);
    }
}
