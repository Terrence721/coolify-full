<?php

declare(strict_types=1);

namespace App\Actions\Application;

use App\Models\Application;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class StopApplicationOneServer
{
    use AsAction;

    public function handle(Application $application, Server $server): ?string
    {
        $destination = $application->destination;
        // The main destination's server may have been soft-deleted already (e.g. mid-flight
        // during a "delete server + force-delete resources" request on that server) - the
        // default belongsTo query excludes trashed rows, so fall back to a trashed-inclusive
        // lookup rather than crashing on a null server here.
        $mainServer = $destination->server ?? $destination->server()->withTrashed()->first();
        if (! $mainServer) {
            return 'Server is not functional';
        }
        if ($mainServer->isSwarm()) {
            return null;
        }
        if (! $server->isFunctional()) {
            return 'Server is not functional';
        }
        try {
            $containers = getCurrentApplicationContainerStatus($server, $application->id, 0);
            $timeout = $application->settings->stopGracePeriodSeconds();

            if ($containers->count() > 0) {
                foreach ($containers as $container) {
                    $containerName = data_get($container, 'Names');
                    if ($containerName) {
                        instant_remote_process(
                            [
                                "docker stop --time=$timeout $containerName",
                                "docker rm -f $containerName",
                            ],
                            $server
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            return $e->getMessage();
        }

        return null;
    }
}
