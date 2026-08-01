<?php

declare(strict_types=1);

namespace App\Actions\Service;

use App\Actions\Server\CleanupDocker;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteService
{
    use AsAction;

    public function handle(Service $service, bool $deleteVolumes, bool $deleteConnectedNetworks, bool $deleteConfigurations, bool $dockerCleanup): void
    {
        $server = data_get($service, 'server');

        // Always cleaned up regardless of $deleteVolumes/server reachability - these are
        // config, not container volumes, and the Service row itself is force-deleted
        // unconditionally below (in finally), so leaving this gated left them permanently
        // orphaned (no FK/cascade on this polymorphic relation) whenever the box was
        // unchecked or the server happened to be unreachable at delete time.
        $service->environment_variables()->delete();

        try {
            if ($deleteVolumes && $server && $server->isFunctional()) {
                $storagesToDelete = collect([]);

                $commands = [];
                foreach ($service->applications()->get() as $application) {
                    $storages = $application->persistentStorages()->get();
                    foreach ($storages as $storage) {
                        $storagesToDelete->push($storage);
                    }
                }
                foreach ($service->databases()->get() as $database) {
                    $storages = $database->persistentStorages()->get();
                    foreach ($storages as $storage) {
                        $storagesToDelete->push($storage);
                    }
                }
                foreach ($storagesToDelete as $storage) {
                    $commands[] = 'docker volume rm -f '.escapeshellarg($storage->name);
                }

                // Execute volume deletion first, this must be done first otherwise volumes will not be deleted.
                if (! empty($commands)) {
                    foreach ($commands as $command) {
                        $result = instant_remote_process([$command], $server, false);
                        if ($result !== null) {
                            Log::error('Error deleting volumes: '.$result);
                        }
                    }
                }
            }

            if ($deleteConnectedNetworks) {
                $service->deleteConnectedNetworks();
            }

            if ($server) {
                instant_remote_process(["docker rm -f $service->uuid"], $server, throwError: false);
            }
        } catch (\Exception $e) {
            throw new \RuntimeException($e->getMessage());
        } finally {
            if ($deleteConfigurations) {
                $service->deleteConfigurations();
            }
            foreach ($service->applications()->get() as $application) {
                $application->forceDelete();
            }
            foreach ($service->databases()->get() as $database) {
                $database->forceDelete();
            }
            foreach ($service->scheduled_tasks as $task) {
                $task->delete();
            }
            $service->tags()->detach();
            $service->forceDelete();

            if ($dockerCleanup && $server) {
                CleanupDocker::dispatch($server, false, false);
            }
        }
    }
}
