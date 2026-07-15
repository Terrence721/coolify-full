<?php

declare(strict_types=1);

namespace App\Traits;

use App\Contracts\StandaloneDatabaseInstance;
use App\Models\Application;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Support\DatabaseEngineRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Server-side Docker container inventory: listing/starting/stopping
 * unmanaged containers, and resolving which applications/databases/services
 * this server hosts (used by definedResources()/hasDefinedResources()).
 * Extracted from App\Models\Server, which still owns the services()
 * relationship these queries feed into.
 */
trait HasDockerContainers
{
    public function getDiskUsage(): ?string
    {
        return instant_remote_process(['df / --output=pcent | tr -cd 0-9'], $this, false);
        // return instant_remote_process(["df /| tail -1 | awk '{ print $5}' | sed 's/%//g'"], $this, false);
    }

    /**
     * @return Collection<int, mixed>
     */
    public function definedResources(): Collection
    {
        $applications = $this->applications();
        $databases = $this->databases();
        $services = $this->services();

        return $applications->concat($databases)->concat($services->get());
    }

    public function stopUnmanaged(string $id): ?string
    {
        return instant_remote_process(['docker stop -t 0 '.escapeshellarg($id)], $this);
    }

    public function restartUnmanaged(string $id): ?string
    {
        return instant_remote_process(['docker restart '.escapeshellarg($id)], $this);
    }

    public function startUnmanaged(string $id): ?string
    {
        return instant_remote_process(['docker start '.escapeshellarg($id)], $this);
    }

    /**
     * @return array{containers: Collection<int, mixed>, containerReplicates: Collection<int, mixed>}
     */
    public function getContainers(): array
    {
        $containers = collect([]);
        $containerReplicates = collect([]);
        if ($this->isSwarm()) {
            $containers = instant_remote_process_with_timeout(["docker service inspect $(docker service ls -q) --format '{{json .}}'"], $this, false);
            $containers = format_docker_command_output_to_json($containers);
            $containerReplicates = instant_remote_process_with_timeout(["docker service ls --format '{{json .}}'"], $this, false);
            if ($containerReplicates) {
                $containerReplicates = format_docker_command_output_to_json($containerReplicates);
                foreach ($containerReplicates as $containerReplica) {
                    $name = data_get($containerReplica, 'Name');
                    $containers = $containers->map(function ($container) use ($name, $containerReplica) {
                        if (data_get($container, 'Spec.Name') === $name) {
                            $replicas = data_get($containerReplica, 'Replicas');
                            $running = str($replicas)->explode('/')[0];
                            $total = str($replicas)->explode('/')[1];
                            if ($running === $total) {
                                data_set($container, 'State.Status', 'running');
                                data_set($container, 'State.Health.Status', 'healthy');
                            } else {
                                data_set($container, 'State.Status', 'starting');
                                data_set($container, 'State.Health.Status', 'unhealthy');
                            }
                        }

                        return $container;
                    });
                }
            }
        } else {
            $containers = instant_remote_process_with_timeout(["docker container inspect $(docker container ls -aq) --format '{{json .}}'"], $this, false);
            $containers = format_docker_command_output_to_json($containers);
            $containerReplicates = collect([]);
        }

        return [
            'containers' => collect($containers) ?? collect([]),
            'containerReplicates' => collect($containerReplicates) ?? collect([]),
        ];
    }

    public function loadAllContainers(): Collection
    {
        if ($this->isFunctional()) {
            $containers = instant_remote_process(["docker ps -a --format '{{json .}}'"], $this);
            $containers = format_docker_command_output_to_json($containers);

            return collect($containers);
        }

        return collect([]);
    }

    public function loadUnmanagedContainers(): Collection
    {
        if ($this->isFunctional()) {
            $containers = instant_remote_process(["docker ps -a --format '{{json .}}'"], $this);
            $containers = format_docker_command_output_to_json($containers);
            $containers = $containers->map(function ($container) {
                $labels = data_get($container, 'Labels');
                if (! str($labels)->contains('coolify.managed')) {
                    return $container;
                }

                return null;
            });
            $containers = $containers->filter();

            return collect($containers);
        } else {
            return collect([]);
        }
    }

    public function hasDefinedResources(): bool
    {
        $applications = $this->applications()->count() > 0;
        $databases = $this->databases()->count() > 0;
        $services = $this->services()->count() > 0;
        if ($applications || $databases || $services) {
            return true;
        }

        return false;
    }

    /**
     * @return Collection<int, Model&StandaloneDatabaseInstance>
     */
    public function databases(): Collection
    {
        // Get destination IDs for this server in two efficient queries
        $standaloneDockerIds = StandaloneDocker::where('server_id', $this->id)->pluck('id');
        $swarmDockerIds = SwarmDocker::where('server_id', $this->id)->pluck('id');

        $destinationCondition = function ($query) use ($standaloneDockerIds, $swarmDockerIds) {
            $query->where(function ($q) use ($standaloneDockerIds) {
                $q->where('destination_type', StandaloneDocker::class)
                    ->whereIn('destination_id', $standaloneDockerIds);
            })->orWhere(function ($q) use ($swarmDockerIds) {
                $q->where('destination_type', SwarmDocker::class)
                    ->whereIn('destination_id', $swarmDockerIds);
            });
        };

        // Query each database engine (see DatabaseEngineRegistry) with the destination condition
        $result = new Collection;
        foreach (DatabaseEngineRegistry::modelClasses() as $modelClass) {
            $result = $result->concat($modelClass::where($destinationCondition)->get());
        }

        return $result->filter(fn ($item) => data_get($item, 'name') !== 'coolify-db');
    }

    /**
     * @return Collection<int, Application>
     */
    public function applications(): Collection
    {
        // Get destination IDs for this server in two efficient queries
        $standaloneDockerIds = StandaloneDocker::where('server_id', $this->id)->pluck('id');
        $swarmDockerIds = SwarmDocker::where('server_id', $this->id)->pluck('id');

        // Query all applications in a single query using polymorphic conditions
        $applications = Application::where(function ($query) use ($standaloneDockerIds, $swarmDockerIds) {
            $query->where(function ($q) use ($standaloneDockerIds) {
                $q->where('destination_type', StandaloneDocker::class)
                    ->whereIn('destination_id', $standaloneDockerIds);
            })->orWhere(function ($q) use ($swarmDockerIds) {
                $q->where('destination_type', SwarmDocker::class)
                    ->whereIn('destination_id', $swarmDockerIds);
            });
        })->get();

        // Get additional server applications
        $additionalApplicationIds = DB::table('additional_destinations')
            ->where('server_id', $this->id)
            ->pluck('application_id');

        if ($additionalApplicationIds->isNotEmpty()) {
            $additionalApps = Application::whereIn('id', $additionalApplicationIds)->get();
            $applications = $applications->concat($additionalApps);
        }

        return $applications;
    }

    /**
     * @return Collection<int, Application>
     */
    public function dockerComposeBasedApplications(): Collection
    {
        return $this->applications()->filter(function ($application) {
            return data_get($application, 'build_pack') === 'dockercompose';
        });
    }

    /**
     * @return Collection<int, mixed>
     */
    public function dockerComposeBasedPreviewDeployments(): Collection
    {
        return $this->previews()->filter(function ($preview) {
            $applicationId = data_get($preview, 'application_id');
            $application = Application::find($applicationId);
            if (! $application) {
                return false;
            }

            return data_get($application, 'build_pack') === 'dockercompose';
        });
    }
}
