<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Actions\Server\CleanupDocker;
use App\Contracts\StandaloneDatabaseInstance;
use App\Events\ServiceStatusChanged;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

class StopDatabase
{
    use AsAction;

    public function handle(Model&StandaloneDatabaseInstance $database, bool $dockerCleanup = true): string
    {
        try {
            $server = $database->destination->server;
            if (! $server->isFunctional()) {
                return 'Server is not functional';
            }

            $this->stopContainer($database, $database->uuid, 30);

            // Reset restart tracking when database is manually stopped
            $database->update([
                'restart_count' => 0,
                'last_restart_at' => null,
                'last_restart_type' => null,
            ]);

            if ($dockerCleanup) {
                CleanupDocker::dispatch($server, false, false);
            }

            if ($database->is_public) {
                StopDatabaseProxy::run($database);
            }

            return 'Database stopped successfully';
        } catch (\Exception $e) {
            return 'Database stop failed: '.$e->getMessage();
        } finally {
            ServiceStatusChanged::dispatch($database->environment->project->team->id);
        }

    }

    private function stopContainer(Model&StandaloneDatabaseInstance $database, string $containerName, int $timeout = 30): void
    {
        $server = $database->destination->server;
        instant_remote_process(command: [
            "docker stop -t $timeout $containerName",
            "docker rm -f $containerName",
        ], server: $server, throwError: false);
    }
}
