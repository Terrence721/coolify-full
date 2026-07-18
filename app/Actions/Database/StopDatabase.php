<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Actions\Server\CleanupDocker;
use App\Models\StandaloneDatabaseInstance;
use App\Events\ServiceStatusChanged;
use Lorisleiva\Actions\Concerns\AsAction;

class StopDatabase
{
    use AsAction;

    public function handle(StandaloneDatabaseInstance $database, bool $dockerCleanup = true): string
    {
        try {
            $server = data_get($database, 'destination.server');
            if (! $server->isFunctional()) {
                return 'Server is not functional';
            }

            $uuid = (string) data_get($database, 'uuid');
            $this->stopContainer($database, $uuid, 30);

            // Reset restart tracking when database is manually stopped
            $database->update([
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

    private function stopContainer(StandaloneDatabaseInstance $database, string $containerName, int $timeout = 30): void
    {
        $server = data_get($database, 'destination.server');
        instant_remote_process(command: [
            "docker stop -t $timeout $containerName",
            "docker rm -f $containerName",
        ], server: $server, throwError: false);
    }
}
