<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Application\StopApplication;
use App\Actions\Docker\GetContainersStatus;
use App\Models\Application;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Visus\Cuid2\Cuid2;

class ApplicationDeploymentController extends Controller
{
    use AuthorizesRequests;

    private const DEFAULT_TAKE = 10;

    public function index(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): Response|RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        $pullRequestId = $this->sanitizePullRequestId($request->query('pull_request_id'));
        $skip = max(0, (int) $request->query('skip', 0));

        ['deployments' => $deployments, 'count' => $count] = $application->deployments($skip, self::DEFAULT_TAKE, $pullRequestId);

        $currentPage = intdiv($skip, self::DEFAULT_TAKE) + 1;
        $showNext = $deployments->count() > 0 && $deployments->count() >= self::DEFAULT_TAKE;
        $showPrev = $skip > 0;

        return Inertia::render('Project/Application/Deployment/Index', [
            'application' => $this->applicationProps($application),
            'heading' => $this->headingProps($application),
            'configurationChecker' => $this->configurationCheckerProps($application),
            'deployments' => $deployments->map(fn ($deployment) => $this->deploymentProps($application, $deployment))->values(),
            'deploymentsCount' => $count,
            'skip' => $skip,
            'defaultTake' => self::DEFAULT_TAKE,
            'currentPage' => $currentPage,
            'showNext' => $showNext,
            'showPrev' => $showPrev,
            'pullRequestId' => $pullRequestId,
            'baseUrl' => $request->url(),
            'parameters' => compact('project_uuid', 'environment_uuid', 'application_uuid'),
            'urls' => [
                'deploy' => route('project.application.deployment.deploy', compact('project_uuid', 'environment_uuid', 'application_uuid')),
                'restart' => route('project.application.deployment.restart', compact('project_uuid', 'environment_uuid', 'application_uuid')),
                'stop' => route('project.application.deployment.stop', compact('project_uuid', 'environment_uuid', 'application_uuid')),
                'checkStatus' => route('project.application.deployment.check-status', compact('project_uuid', 'environment_uuid', 'application_uuid')),
            ],
        ]);
    }

    public function deploy(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid, redirectOnMissing: true);
        if (! $application instanceof Application) {
            return $application;
        }

        return $this->doDeploy($application, $project_uuid, $environment_uuid, $application_uuid, forceRebuild: (bool) $request->boolean('force_rebuild'));
    }

    public function restart(string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid, redirectOnMissing: true);
        if (! $application instanceof Application) {
            return $application;
        }

        $this->authorize('deploy', $application);

        if ($application->additional_servers->count() > 0 && str($application->docker_registry_image_name)->isEmpty()) {
            return back()->with('error', 'Before deploying to multiple servers, you must first set a Docker image in the General tab.');
        }

        $deploymentUuid = (string) new Cuid2;
        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: $deploymentUuid,
            restart_only: true,
        );

        if ($result['status'] === 'queue_full' || $result['status'] === 'skipped') {
            return back()->with('error', $result['message']);
        }

        return redirect()->route('project.application.deployment.show', [
            'project_uuid' => $project_uuid,
            'environment_uuid' => $environment_uuid,
            'application_uuid' => $application_uuid,
            'deployment_uuid' => $deploymentUuid,
        ]);
    }

    public function stop(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid, redirectOnMissing: true);
        if (! $application instanceof Application) {
            return $application;
        }

        $this->authorize('deploy', $application);

        StopApplication::dispatch($application, false, $request->boolean('docker_cleanup', true));

        return back()->with('info', 'Gracefully stopping application. It could take a while depending on the application.');
    }

    public function checkStatus(string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid, redirectOnMissing: true);
        if (! $application instanceof Application) {
            return $application;
        }

        if (! $application->destination->server->isFunctional()) {
            return back()->with('error', 'Server is not functional.');
        }

        GetContainersStatus::dispatch($application->destination->server);

        return back();
    }

    private function doDeploy(Application $application, string $project_uuid, string $environment_uuid, string $application_uuid, bool $forceRebuild): RedirectResponse
    {
        $this->authorize('deploy', $application);

        if ($application->build_pack === 'dockercompose' && is_null($application->docker_compose_raw)) {
            return back()->with('error', 'Please load a Compose file first.');
        }
        if ($application->destination->server->isSwarm() && str($application->docker_registry_image_name)->isEmpty()) {
            return back()->with('error', 'To deploy to a Swarm cluster you must set a Docker image name first.');
        }
        if (data_get($application, 'settings.is_build_server_enabled') && str($application->docker_registry_image_name)->isEmpty()) {
            return back()->with('error', 'To use a build server, you must first set a Docker image.');
        }
        if ($application->additional_servers->count() > 0 && str($application->docker_registry_image_name)->isEmpty()) {
            return back()->with('error', 'Before deploying to multiple servers, you must first set a Docker image in the General tab.');
        }

        $deploymentUuid = (string) new Cuid2;
        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: $deploymentUuid,
            force_rebuild: $forceRebuild,
        );

        if ($result['status'] === 'queue_full' || $result['status'] === 'skipped') {
            return back()->with('error', $result['message']);
        }

        return redirect()->route('project.application.deployment.show', [
            'project_uuid' => $project_uuid,
            'environment_uuid' => $environment_uuid,
            'application_uuid' => $application_uuid,
            'deployment_uuid' => $deploymentUuid,
        ]);
    }

    private function resolveApplication(string $project_uuid, string $environment_uuid, string $application_uuid, bool $redirectOnMissing = false): Application|RedirectResponse
    {
        $project = currentTeam()->load(['projects'])->projects->where('uuid', $project_uuid)->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('uuid', $environment_uuid)->first();
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        $environment->load(['applications']);
        $application = $environment->applications->where('uuid', $application_uuid)->first();
        if (! $application) {
            return redirect()->route('dashboard');
        }

        return $application;
    }

    private function sanitizePullRequestId(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || (float) $value <= 0 || (float) $value != (int) $value) {
            return null;
        }

        return (string) (int) $value;
    }

    private function applicationProps(Application $application): array
    {
        return [
            'uuid' => $application->uuid,
            'name' => $application->name,
        ];
    }

    private function headingProps(Application $application): array
    {
        $lastDeployment = $application->get_last_successful_deployment();

        return [
            'lastDeploymentInfo' => trim(str($lastDeployment?->commit)->limit(7).' '.($lastDeployment?->commit_message ?? '')),
            'lastDeploymentLink' => $application->gitCommitLink((string) $lastDeployment?->commit),
        ];
    }

    private function configurationCheckerProps(Application $application): array
    {
        $diff = $application->pendingDeploymentConfigurationDiff();
        $redactEnvironment = ! (bool) auth()->user()?->isAdmin();

        $array = $diff->toArray();
        if ($redactEnvironment) {
            $array['changes'] = collect($array['changes'] ?? [])->map(function (array $change) {
                if (data_get($change, 'section') !== 'environment') {
                    return $change;
                }
                $change['old_display_value'] = data_get($change, 'old_display_value') === '-' ? '-' : '••••••••';
                $change['new_display_value'] = data_get($change, 'new_display_value') === '-' ? '-' : '••••••••';
                $change['old_full_value'] = null;
                $change['new_full_value'] = null;
                $change['expandable'] = false;

                return $change;
            })->all();
        }

        return [
            'isConfigurationChanged' => $diff->isChanged(),
            'isExited' => $application->isExited(),
            'configHash' => $application->config_hash,
            'diff' => $array,
        ];
    }

    private function deploymentProps(Application $application, $deployment): array
    {
        return [
            'deployment_uuid' => $deployment->deployment_uuid,
            'status' => $deployment->status,
            'commit' => $deployment->commit,
            'commit_message' => $deployment->commitMessage(),
            'commit_link' => $deployment->commit ? $application->gitCommitLink($deployment->commit) : null,
            'is_webhook' => $deployment->is_webhook,
            'is_api' => $deployment->is_api,
            'rollback' => $deployment->rollback,
            'pull_request_id' => $deployment->pull_request_id,
            'server_name' => $deployment->server_name,
            'has_additional_servers' => $application->additional_servers->count() > 0,
            'started_at' => $deployment->status !== 'queued' ? formatDateInServerTimezone($deployment->created_at, $application->destination->server) : null,
            'finished_at' => ($deployment->finished_at && ! in_array($deployment->status, ['in_progress', 'cancelled-by-user']))
                ? formatDateInServerTimezone($deployment->finished_at, $application->destination->server)
                : null,
            'duration' => ($deployment->finished_at && ! in_array($deployment->status, ['in_progress', 'cancelled-by-user']))
                ? calculateDuration($deployment->created_at, $deployment->finished_at)
                : ($deployment->status === 'in_progress' ? calculateDuration($deployment->created_at, now()) : null),
            'finished_ago' => $deployment->finished_at ? Carbon::parse($deployment->finished_at)->diffForHumans() : null,
        ];
    }
}
