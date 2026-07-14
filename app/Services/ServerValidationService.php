<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Enums\ProxyTypes;
use App\Events\ServerValidated;
use App\Models\Server;

/**
 * Extracted from BoardingController::validateServer() (Phase 77) on its second real consumer,
 * ServerShowController (Phase 78) — the "Validate Server & Install Docker Engine" flow needs the
 * exact same connection->OS->prerequisites->Docker->version->proxy-finalize orchestration.
 */
class ServerValidationService
{
    private const MAX_ATTEMPTS = 3;

    /**
     * @return array<string, mixed>
     */
    public function validate(Server $server, bool $install, int $attempt): array
    {
        app(ConfigurationRepository::class)->disableSshMux();

        $connection = $server->validateConnection();
        if (! $connection['uptime']) {
            return ['status' => 'unreachable', 'error' => $connection['error']];
        }

        if (! $server->validateOS()) {
            return ['status' => 'unsupported_os'];
        }

        $prerequisites = $server->validatePrerequisites();
        if (! $prerequisites['success']) {
            if (! $install) {
                $missing = implode(', ', $prerequisites['missing']);

                return ['status' => 'failed', 'error' => "Prerequisites ({$missing}) are not installed. Please install them before continuing."];
            }
            if ($attempt >= self::MAX_ATTEMPTS) {
                $missing = implode(', ', $prerequisites['missing']);

                return ['status' => 'failed', 'error' => "Prerequisites ({$missing}) could not be installed after ".self::MAX_ATTEMPTS.' attempts. Please install them manually.'];
            }
            $activity = $server->installPrerequisites();

            return ['status' => 'installing', 'step' => 'prerequisites', 'activityId' => $activity->id, 'attempt' => $attempt + 1];
        }

        $dockerReady = $server->validateDockerEngine() && $server->validateDockerCompose();
        if (! $dockerReady) {
            if (! $install) {
                return ['status' => 'failed', 'error' => 'Docker Engine is not installed. Please install Docker manually before continuing.'];
            }
            if ($attempt >= self::MAX_ATTEMPTS) {
                return ['status' => 'failed', 'error' => 'Docker Engine could not be installed. Please install Docker manually before continuing.'];
            }
            $activity = $server->installDocker();

            return ['status' => 'installing', 'step' => 'docker', 'activityId' => $activity->id, 'attempt' => $attempt + 1];
        }

        if ($server->isSwarm()) {
            try {
                $server->validateDockerSwarm();
            } catch (\Throwable $e) {
                return ['status' => 'failed', 'error' => $e->getMessage()];
            }
        } elseif (! $server->validateDockerEngineVersion()) {
            $requiredVersion = str(config('constants.docker.minimum_required_version'))->before('.');

            return ['status' => 'failed', 'error' => "Minimum Docker Engine version {$requiredVersion} is not installed. Please install Docker manually before continuing."];
        }

        $server->update(['is_validating' => false]);
        $server->gatherServerMetadata();

        ServerValidated::dispatch($server->team_id, $server->uuid);

        $server->proxy->type = ProxyTypes::TRAEFIK->value;
        $server->proxy->status = 'exited';
        $server->proxy->last_saved_settings = null;
        $server->proxy->last_applied_settings = null;
        $server->save();

        $proxyShouldRun = CheckProxy::run($server, true);
        if ($proxyShouldRun) {
            instant_remote_process(ensureProxyNetworksExist($server)->toArray(), $server, false);
            StartProxy::dispatch($server);
        }

        return ['status' => 'validated', 'serverUuid' => $server->uuid];
    }
}
