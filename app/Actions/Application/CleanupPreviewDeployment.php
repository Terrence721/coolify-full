<?php

declare(strict_types=1);

namespace App\Actions\Application;

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class CleanupPreviewDeployment
{
    use AsAction;

    public string $jobQueue = 'high';

    /**
     * @return array{cancelled_deployments: int, killed_containers: int, status: string, message?: string}
     */
    public function handle(
        Application $application,
        int $pull_request_id,
        ?ApplicationPreview $preview = null
    ): array {
        $result = [
            'cancelled_deployments' => 0,
            'killed_containers' => 0,
            'status' => 'success',
        ];

        if (! $application->destination) {
            return [
                ...$result,
                'status' => 'failed',
                'message' => 'Application has no destination',
            ];
        }

        /** @var StandaloneDocker|SwarmDocker $destination */
        $destination = $application->destination;
        $server = $destination->server;

        if (! $server || ! $server->isFunctional()) {
            return [
                ...$result,
                'status' => 'failed',
                'message' => 'Server is not functional',
            ];
        }

        $result['cancelled_deployments'] = $this->cancelActiveDeployments(
            $application,
            $pull_request_id,
            $server
        );

        $result['killed_containers'] = $this->stopRunningContainers(
            $application,
            $pull_request_id,
            $server
        );

        if (! $preview) {
            $preview = ApplicationPreview::where('application_id', $application->id)
                ->where('pull_request_id', $pull_request_id)
                ->first();
        }

        if ($preview) {
            DeleteResourceJob::dispatch($preview);
        }

        return $result;
    }

    private function cancelActiveDeployments(
        Application $application,
        int $pull_request_id,
        Server $server
    ): int {
        $activeDeployments = ApplicationDeploymentQueue::where('application_id', $application->id)
            ->where('pull_request_id', $pull_request_id)
            ->whereIn('status', [
                ApplicationDeploymentStatus::QUEUED->value,
                ApplicationDeploymentStatus::IN_PROGRESS->value,
            ])
            ->get();

        $cancelled = 0;
        foreach ($activeDeployments as $deployment) {
            try {
                // Mark deployment as cancelled
                $deployment->update([
                    'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
                ]);

                // Add cancellation log entry
                $deployment->addLogEntry('Deployment cancelled: Pull request closed.', 'stderr');

                // Try to kill helper container if it exists
                $this->killHelperContainer($deployment->deployment_uuid, $server);
                $cancelled++;
            } catch (\Throwable $e) {
                Log::warning("Failed to cancel deployment {$deployment->id}: {$e->getMessage()}");
            }
        }

        return $cancelled;
    }

    private function killHelperContainer(string $deployment_uuid, Server $server): void
    {
        try {
            $escapedUuid = escapeshellarg($deployment_uuid);
            $checkCommand = "docker ps -a --filter name={$escapedUuid} --format '{{.Names}}'";
            $containerExists = instant_remote_process([$checkCommand], $server);

            if ($containerExists && str($containerExists)->trim()->isNotEmpty()) {
                instant_remote_process(["docker rm -f {$escapedUuid}"], $server);
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in killHelperContainer().', ['error' => $e->getMessage()]);

            // Silently handle - container may already be gone
        }
    }

    private function stopRunningContainers(
        Application $application,
        int $pull_request_id,
        Server $server
    ): int {
        $killed = 0;

        try {
            if ($server->isSwarm()) {
                $escapedStackName = escapeshellarg("{$application->uuid}-{$pull_request_id}");
                instant_remote_process(["docker stack rm {$escapedStackName}"], $server);
                $killed++;
            } else {
                $containers = getCurrentApplicationContainerStatus(
                    $server,
                    $application->id,
                    $pull_request_id
                );

                if ($containers->isNotEmpty()) {
                    foreach ($containers as $container) {
                        $containerName = data_get($container, 'Names');
                        if ($containerName) {
                            $escapedContainerName = escapeshellarg($containerName);
                            instant_remote_process(
                                ["docker rm -f {$escapedContainerName}"],
                                $server
                            );
                            $killed++;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Error stopping containers for PR #{$pull_request_id}: {$e->getMessage()}");
        }

        return $killed;
    }
}
