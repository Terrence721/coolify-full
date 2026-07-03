<?php

namespace App\Jobs;

use App\Actions\Docker\GetContainersStatus;
use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Actions\Server\StartLogDrain;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Container\ContainerRestarted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ServerCheckJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * @var Collection<int, array<string, mixed>>|null
     */
    public $containers = null;

    private function notifyServerTeam(ContainerRestarted $notification): void
    {
        $team = $this->server->team;
        if ($team instanceof Team) {
            $team->notify($notification);
        }
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('server-check-'.$this->server->uuid))->expireAfter(60)->dontRelease()];
    }

    public function __construct(public Server $server) {}

    public function failed(?\Throwable $exception): void
    {
        if ($exception instanceof TimeoutExceededException) {
            Log::warning('ServerCheckJob timed out', [
                'server_id' => $this->server->id,
                'server_name' => $this->server->name,
            ]);
            $this->server->increment('unreachable_count');

            // Delete the queue job so it doesn't appear in Horizon's failed list.
            $this->job?->delete();
        }
    }

    public function handle(): ?string
    {
        try {
            if ($this->server->serverStatus() === false) {
                return 'Server is not reachable or not ready.';
            }

            if (! $this->server->isSwarmWorker() && ! $this->server->isBuildServer()) {
                ['containers' => $this->containers, 'containerReplicates' => $containerReplicates] = $this->server->getContainers();
                if (is_null($this->containers)) {
                    return 'No containers found.';
                }
                GetContainersStatus::run($this->server, $this->containers, $containerReplicates);

                if ($this->server->isSentinelEnabled()) {
                    CheckAndStartSentinelJob::dispatch($this->server);
                }

                if ($this->server->isLogDrainEnabled()) {
                    $this->checkLogDrainContainer();
                }

                if ($this->server->proxySet() && ! (bool) data_get($this->server, 'proxy.force_stop', false)) {
                    $this->server->proxyType();
                    $foundProxyContainer = $this->containers->filter(function ($value, $key) {
                        if ($this->server->isSwarm()) {
                            return data_get($value, 'Spec.Name') === 'coolify-proxy_traefik';
                        } else {
                            return data_get($value, 'Name') === '/coolify-proxy';
                        }
                    })->first();
                    if (! $foundProxyContainer) {
                        try {
                            $shouldStart = CheckProxy::run($this->server);
                            if ($shouldStart) {
                                StartProxy::run($this->server, async: false);
                                $this->notifyServerTeam(new ContainerRestarted('coolify-proxy', $this->server));
                            }
                        } catch (\Throwable $e) {
                        }
                    } else {
                        $proxy = $this->server->proxy;
                        if ($proxy) {
                            $proxy->setAttribute('status', data_get($foundProxyContainer, 'State.Status'));
                            $proxy->save();
                        }
                        ConnectProxyToNetworksJob::dispatchSync($this->server);
                    }
                }
            }

            return null;
        } catch (\Throwable $e) {
            return (string) handleError($e);
        }
    }

    private function checkLogDrainContainer(): void
    {
        $foundLogDrainContainer = $this->containers->filter(function ($value, $key) {
            return data_get($value, 'Name') === '/coolify-log-drain';
        })->first();
        if ($foundLogDrainContainer) {
            $status = data_get($foundLogDrainContainer, 'State.Status');
            if ($status !== 'running') {
                StartLogDrain::dispatch($this->server);
            }
        } else {
            StartLogDrain::dispatch($this->server);
        }
    }
}
