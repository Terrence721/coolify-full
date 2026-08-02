<?php

declare(strict_types=1);

namespace App\Actions\Application;

use App\Actions\Server\CleanupDocker;
use App\Actions\Shared\ComplexStatusCheck;
use App\Events\ServiceStatusChanged;
use App\Models\Application;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Lorisleiva\Actions\Concerns\AsAction;

class StopApplication
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Application $application, bool $previewDeployments = false, bool $dockerCleanup = true, bool $resetRestartCount = true): ?string
    {
        /** @var StandaloneDocker|SwarmDocker $destination */
        $destination = $application->destination;
        // The destination's server may have been soft-deleted already (e.g. mid-flight during a
        // "delete server + force-delete resources" request, where the server row is soft-deleted
        // synchronously while this action runs later via a queued DeleteResourceJob) - the default
        // belongsTo query excludes trashed rows, so fall back to a trashed-inclusive lookup rather
        // than silently losing the ability to reach and stop the real remote containers.
        $server = $destination->server ?? $destination->server()->withTrashed()->first();
        $servers = collect([$server]);
        if ($application->additional_servers->count() > 0) {
            $servers = $servers->merge($application->additional_servers);
        }
        foreach ($servers as $server) {
            try {
                if (! $server || ! $server->isFunctional()) {
                    return 'Server is not functional';
                }

                if ($server->isSwarm()) {
                    instant_remote_process(["docker stack rm {$application->uuid}"], $server);

                    return null;
                }

                $containers = $previewDeployments
                    ? getCurrentApplicationContainerStatus($server, $application->id, includePullrequests: true)
                    : getCurrentApplicationContainerStatus($server, $application->id, 0);

                $containersToStop = $containers->pluck('Names')->toArray();
                $timeout = $application->settings->stopGracePeriodSeconds();

                foreach ($containersToStop as $containerName) {
                    instant_remote_process(command: [
                        "docker stop --time=$timeout $containerName",
                        "docker rm -f $containerName",
                    ], server: $server, throwError: false);
                }

                if ($application->build_pack === 'dockercompose') {
                    $application->deleteConnectedNetworks();
                }

                if ($dockerCleanup) {
                    CleanupDocker::dispatch($server, false, false);
                }
            } catch (\Exception $e) {
                return $e->getMessage();
            }
        }

        $application->update(['status' => 'exited']);

        if ($resetRestartCount) {
            $application->update([
                'restart_count' => 0,
                'last_restart_at' => null,
                'last_restart_type' => null,
            ]);
        }

        if ($application->additional_servers->count() > 0) {
            ComplexStatusCheck::run($application);
        }

        ServiceStatusChanged::dispatch($application->environment->project->team->id);

        return null;
    }
}
