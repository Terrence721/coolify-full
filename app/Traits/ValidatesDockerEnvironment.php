<?php

declare(strict_types=1);

namespace App\Traits;

use App\Actions\Server\InstallDocker;
use App\Actions\Server\InstallPrerequisites;
use App\Actions\Server\ValidatePrerequisites;
use App\Events\ServerReachabilityChanged;
use Illuminate\Support\Facades\Log;

/**
 * Docker engine/compose/swarm prerequisite installation and validation for a
 * server. Extracted from App\Models\Server.
 */
trait ValidatesDockerEnvironment
{
    public function installDocker(): mixed
    {
        return InstallDocker::run($this);
    }

    /**
     * Validate that required commands are available on the server.
     *
     * @return array{success: bool, missing: array<string>, found: array<string>}
     */
    public function validatePrerequisites(): array
    {
        return ValidatePrerequisites::run($this);
    }

    public function installPrerequisites(): mixed
    {
        return InstallPrerequisites::run($this);
    }

    public function validateDockerEngine(bool $throwError = false): bool
    {
        $dockerBinary = instant_remote_process(['command -v docker'], $this, false, no_sudo: true);
        if (is_null($dockerBinary)) {
            $this->settings->is_usable = false;
            $this->settings->save();
            if ($throwError) {
                throw new \Exception('Server is not usable. Docker Engine is not installed.');
            }

            return false;
        }
        try {
            instant_remote_process(['docker version'], $this);
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in validateDockerEngine().', ['error' => $e->getMessage()]);

            $this->settings->is_usable = false;
            $this->settings->save();
            if ($throwError) {
                throw new \Exception('Server is not usable. Docker Engine is not running.');
            }

            return false;
        }
        $this->settings->is_usable = true;
        $this->settings->save();
        $this->validateCoolifyNetwork(isSwarm: false, isBuildServer: $this->settings->is_build_server);

        return true;
    }

    public function validateDockerCompose(bool $throwError = false): bool
    {
        $dockerCompose = instant_remote_process(['docker compose version'], $this, false);
        if (is_null($dockerCompose)) {
            $this->settings->is_usable = false;
            $this->settings->save();
            if ($throwError) {
                throw new \Exception('Server is not usable. Docker Compose is not installed.');
            }

            return false;
        }
        $this->settings->is_usable = true;
        $this->settings->save();

        return true;
    }

    public function validateDockerSwarm(): bool
    {
        $swarmStatus = instant_remote_process(['docker info|grep -i swarm'], $this, false);
        $swarmStatus = str($swarmStatus)->trim()->after(':')->trim()->value();
        if ($swarmStatus === 'inactive') {
            throw new \Exception('Docker Swarm is not initiated. Please join the server to a swarm before continuing.');
        }
        $this->settings->is_usable = true;
        $this->settings->save();
        $this->validateCoolifyNetwork(isSwarm: true);

        return true;
    }

    public function validateDockerEngineVersion(): bool
    {
        $dockerVersionRaw = instant_remote_process(['docker version --format json'], $this, false);
        $dockerVersionJson = json_decode($dockerVersionRaw, true);
        $dockerVersion = data_get($dockerVersionJson, 'Server.Version', '0.0.0');
        $dockerVersion = checkMinimumDockerEngineVersion($dockerVersion);
        if (is_null($dockerVersion)) {
            $this->settings->is_usable = false;
            $this->settings->save();

            return false;
        }
        $this->settings->is_reachable = true;
        $this->settings->is_usable = true;
        $this->settings->save();
        ServerReachabilityChanged::dispatch($this);

        return true;
    }

    public function validateCoolifyNetwork(bool $isSwarm = false, bool $isBuildServer = false): ?string
    {
        if ($isBuildServer) {
            return null;
        }
        if ($isSwarm) {
            return instant_remote_process(['docker network create --attachable --driver overlay coolify-overlay >/dev/null 2>&1 || true'], $this, false);
        } else {
            return instant_remote_process(['docker network create coolify --attachable >/dev/null 2>&1 || true'], $this, false);
        }
    }

    public function isNonRoot(): bool
    {
        return $this->user !== 'root';
    }

    public function isBuildServer(): bool
    {
        return (bool) $this->settings->is_build_server;
    }
}
