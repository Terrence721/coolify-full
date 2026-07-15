<?php

declare(strict_types=1);

namespace App\Traits;

/**
 * Shared workdir/network cleanup for Application and Service, run when a
 * resource is deleted. Extracted from both models, which had near-identical
 * (Application) or byte-identical (Service) implementations.
 */
trait HasResourceCleanup
{
    public function deleteConfigurations(): void
    {
        $server = data_get($this, 'destination.server');
        $workdir = $this->workdir();
        if (str($workdir)->endsWith($this->uuid)) {
            instant_remote_process(['rm -rf '.$this->workdir()], $server, false);
        }
    }

    public function deleteConnectedNetworks(): void
    {
        $server = data_get($this, 'destination.server');
        instant_remote_process(["docker network disconnect {$this->uuid} coolify-proxy"], $server, false);
        instant_remote_process(["docker network rm {$this->uuid}"], $server, false);
    }
}
