<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Application\StopApplication;
use App\Actions\Database\StopDatabase;
use App\Actions\Server\CleanupDocker;
use App\Actions\Service\DeleteService;
use App\Actions\Service\StopService;
use App\Models\StandaloneDatabaseInstance;
use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\Service;
use App\Support\DatabaseEngineRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class DeleteResourceJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Application|ApplicationPreview|Service|StandaloneDatabaseInstance $resource,
        public bool $deleteVolumes = true,
        public bool $deleteConnectedNetworks = true,
        public bool $deleteConfigurations = true,
        public bool $dockerCleanup = true
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        try {
            // Handle ApplicationPreview instances separately
            if ($this->resource instanceof ApplicationPreview) {
                $this->deleteApplicationPreview();

                return;
            }

            $type = $this->resource->type();

            if ($type === 'application') {
                StopApplication::run($this->resource, previewDeployments: true, dockerCleanup: $this->dockerCleanup);
            } elseif (in_array($type, DatabaseEngineRegistry::types())) {
                StopDatabase::run($this->resource, dockerCleanup: $this->dockerCleanup);
            } elseif ($type === 'service') {
                StopService::run($this->resource, $this->deleteConnectedNetworks, $this->dockerCleanup);
                DeleteService::run($this->resource, $this->deleteVolumes, $this->deleteConnectedNetworks, $this->deleteConfigurations, $this->dockerCleanup);

                return;
            }

            if ($this->deleteConfigurations) {
                $this->resource->deleteConfigurations();
            }
            if ($this->deleteVolumes) {
                $this->resource->deleteVolumes();
                $this->resource->persistentStorages()->delete();
            }
            $this->resource->fileStorages()->delete(); // these are file mounts which should probably have their own flag

            if ($this->resource instanceof StandaloneDatabaseInstance) {
                $this->resource->sslCertificates()->delete();
                $this->resource->scheduledBackups()->delete();
                $this->resource->tags()->detach();
            }
            $this->resource->environment_variables()->delete();

            if ($this->deleteConnectedNetworks && $this->resource->type() === 'application') {
                $this->resource->deleteConnectedNetworks();
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in handle().', ['error' => $e->getMessage()]);

            throw $e;
        } finally {
            $this->resource->forceDelete();
            if ($this->dockerCleanup) {
                $server = data_get($this->resource, 'server') ?? data_get($this->resource, 'destination.server');
                if ($server) {
                    CleanupDocker::dispatch($server, false, false);
                }
            }
            Artisan::queue('cleanup:stucked-resources');
        }
    }

    private function deleteApplicationPreview(): void
    {
        $application = $this->resource->application;
        $server = $application->destination->server;
        $pull_request_id = $this->resource->pull_request_id;

        // Ensure the preview is soft deleted (may already be done in Livewire component)
        if (! $this->resource->trashed()) {
            $this->resource->delete();
        }

        // Cancel any active deployments for this PR (same logic as API cancel_deployment)
        $activeDeployments = ApplicationDeploymentQueue::where('application_id', $application->id)
            ->where('pull_request_id', $pull_request_id)
            ->whereIn('status', [
                ApplicationDeploymentStatus::QUEUED->value,
                ApplicationDeploymentStatus::IN_PROGRESS->value,
            ])
            ->get();

        foreach ($activeDeployments as $activeDeployment) {
            try {
                // Mark deployment as cancelled
                $activeDeployment->update([
                    'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
                ]);

                // Add cancellation log entry
                $activeDeployment->addLogEntry('Deployment cancelled: Pull request closed.', 'stderr');

                // Check if helper container exists and kill it
                $deployment_uuid = $activeDeployment->deployment_uuid;
                $escapedDeploymentUuid = escapeshellarg($deployment_uuid);
                $checkCommand = "docker ps -a --filter name={$escapedDeploymentUuid} --format '{{.Names}}'";
                $containerExists = instant_remote_process([$checkCommand], $server);

                if ($containerExists && str($containerExists)->trim()->isNotEmpty()) {
                    instant_remote_process(["docker rm -f {$escapedDeploymentUuid}"], $server);
                    $activeDeployment->addLogEntry('Deployment container stopped.');
                } else {
                    $activeDeployment->addLogEntry('Helper container not yet started. Deployment will be cancelled when job checks status.');
                }

            } catch (\Throwable $e) {
                Log::error('Unhandled exception in deleteApplicationPreview().', ['error' => $e->getMessage()]);

                // Silently handle errors during deployment cancellation
            }
        }

        try {
            if ($server->isSwarm()) {
                $escapedStackName = escapeshellarg("{$application->uuid}-{$pull_request_id}");
                instant_remote_process(["docker stack rm {$escapedStackName}"], $server);
            } else {
                $containers = getCurrentApplicationContainerStatus($server, $application->id, $pull_request_id)->toArray();
                $this->stopPreviewContainers($containers, $server);
            }
        } catch (\Throwable $e) {
            // Log the error but don't fail the job
            Log::warning('Error stopping preview containers for application '.$application->uuid.', PR #'.$pull_request_id.': '.$e->getMessage());
        }

        // Finally, force delete to trigger resource cleanup
        $this->resource->forceDelete();
    }

    /**
     * @param  array<int, array<string, mixed>>  $containers
     */
    private function stopPreviewContainers(array $containers, Server $server, int $timeout = 30): void
    {
        if (empty($containers)) {
            return;
        }

        $containerNames = [];
        foreach ($containers as $container) {
            $containerNames[] = str_replace('/', '', $container['Names']);
        }

        $containerList = implode(' ', array_map('escapeshellarg', $containerNames));
        $commands = [
            "docker stop -t $timeout $containerList",
            "docker rm -f $containerList",
        ];
        instant_remote_process(
            command: $commands,
            server: $server,
            throwError: false
        );
    }
}
