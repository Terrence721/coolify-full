<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Application;

/**
 * Builds the props ApplicationHeading.jsx needs (status/build-pack/swarm flags, the
 * last-deployment link, and the deploy/restart/stop/check-status action URLs), extracted
 * from ProjectLogsController once ProjectApplicationConfigurationController needed the
 * identical shape for the Configuration page's shell (Phase 64). The deploy/restart/stop/
 * check-status routes themselves were already ported (as part of the application
 * deployment/logs pages) — this trait only builds the props pointing at them.
 */
trait ManagesApplicationHeading
{
    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function applicationHeadingProps(Application $application, array $parameters): array
    {
        $lastDeployment = $application->get_last_successful_deployment();

        return [
            'application' => [
                'uuid' => $application->uuid,
                'name' => $application->name,
                'status' => $application->status,
                'buildPack' => $application->build_pack,
                'hasDockerCompose' => ! is_null($application->docker_compose_raw),
                'isSwarm' => (bool) $application->destination?->server?->isSwarm(),
            ],
            'heading' => [
                'lastDeploymentInfo' => trim(data_get_str($lastDeployment, 'commit')->limit(7).' '.data_get($lastDeployment, 'commit_message')),
                'lastDeploymentLink' => $application->gitCommitLink((string) data_get($lastDeployment, 'commit')),
            ],
            'headingUrls' => [
                'deploy' => route('project.application.deployment.deploy', $parameters),
                'restart' => route('project.application.deployment.restart', $parameters),
                'stop' => route('project.application.deployment.stop', $parameters),
                'checkStatus' => route('project.application.deployment.check-status', $parameters),
            ],
        ];
    }
}
