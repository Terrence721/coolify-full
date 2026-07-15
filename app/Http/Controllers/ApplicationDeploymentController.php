<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Application\StopApplication;
use App\Actions\Docker\GetContainersStatus;
use App\Enums\ApplicationDeploymentStatus;
use App\Http\Controllers\Concerns\ManagesApplicationHeading;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Visus\Cuid2\Cuid2;

class ApplicationDeploymentController extends Controller
{
    use AuthorizesRequests;
    use ManagesApplicationHeading;

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

        $parameters = compact('project_uuid', 'environment_uuid', 'application_uuid');
        $headingProps = $this->applicationHeadingProps($application, $parameters);

        return Inertia::render('Project/Application/Deployment/Index', [
            'application' => $headingProps['application'],
            'heading' => $headingProps['heading'],
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
            'parameters' => $parameters,
            'urls' => $headingProps['headingUrls'],
        ]);
    }

    public function show(string $project_uuid, string $environment_uuid, string $application_uuid, string $deployment_uuid): Response|RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if (! $application instanceof Application) {
            return $application;
        }

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', $deployment_uuid)->first();
        if (! $deployment) {
            return redirect()->route('project.application.deployment.index', compact('project_uuid', 'environment_uuid', 'application_uuid'));
        }

        $parameters = compact('project_uuid', 'environment_uuid', 'application_uuid');
        $deploymentParameters = [...$parameters, 'deployment_uuid' => $deployment_uuid];

        $logLines = decode_remote_command_output($deployment)->map(fn (array $line) => [
            'timestamp' => $line['timestamp'],
            'line' => trim($line['line']),
            'hidden' => (bool) ($line['hidden'] ?? false),
            'stderr' => (bool) ($line['stderr'] ?? false),
            'command' => (bool) ($line['command'] ?? false),
        ])->values();

        $headingProps = $this->applicationHeadingProps($application, $parameters);

        return Inertia::render('Project/Application/Deployment/Show', [
            'application' => $headingProps['application'],
            'heading' => $headingProps['heading'],
            'configurationChecker' => $this->configurationCheckerProps($application),
            'deployment' => [
                'deployment_uuid' => $deployment->deployment_uuid,
                'status' => $deployment->status,
            ],
            'isDebugEnabled' => (bool) $application->settings->is_debug_enabled,
            'isKeepAliveOn' => ! in_array($deployment->status, [
                ApplicationDeploymentStatus::FINISHED->value,
                ApplicationDeploymentStatus::FAILED->value,
            ]),
            'logLines' => $logLines,
            'parameters' => $parameters,
            'urls' => [
                ...$headingProps['headingUrls'],
                'toggleDebug' => route('project.application.deployment.toggle-debug', $parameters),
                'forceStart' => route('project.application.deployment.force-start', $deploymentParameters),
                'cancel' => route('project.application.deployment.cancel', $deploymentParameters),
                'downloadAllLogs' => route('project.application.deployment.download-all-logs', $deploymentParameters),
            ],
        ]);
    }

    public function toggleDebug(string $project_uuid, string $environment_uuid, string $application_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid, redirectOnMissing: true);
        if (! $application instanceof Application) {
            return $application;
        }

        $this->authorize('update', $application);

        $application->settings->is_debug_enabled = ! $application->settings->is_debug_enabled;
        $application->settings->save();

        return back();
    }

    public function forceStart(string $project_uuid, string $environment_uuid, string $application_uuid, string $deployment_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid, redirectOnMissing: true);
        if (! $application instanceof Application) {
            return $application;
        }

        $this->authorize('deploy', $application);

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', $deployment_uuid)->first();
        if (! $deployment) {
            return back()->with('error', 'Deployment not found.');
        }

        try {
            force_start_deployment($deployment);
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in forceStart().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    public function cancel(string $project_uuid, string $environment_uuid, string $application_uuid, string $deployment_uuid): RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid, redirectOnMissing: true);
        if (! $application instanceof Application) {
            return $application;
        }

        $this->authorize('deploy', $application);

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', $deployment_uuid)->first();
        if (! $deployment) {
            return back()->with('error', 'Deployment not found.');
        }

        $kill_command = "docker rm -f {$deployment_uuid}";
        $build_server_id = $deployment->build_server_id ?? $application->destination->server_id;
        $server_id = $deployment->server_id ?? $application->destination->server_id;

        $deployment->update(['status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value]);

        $server = null;

        try {
            if ($application->settings->is_build_server_enabled) {
                $server = Server::ownedByCurrentTeam()->find($build_server_id);
            } else {
                $server = Server::ownedByCurrentTeam()->find($server_id);
            }

            if ($deployment->logs) {
                $previous_logs = json_decode($deployment->logs, associative: true, flags: JSON_THROW_ON_ERROR);
                $previous_logs[] = [
                    'command' => $kill_command,
                    'output' => 'Deployment cancelled by user.',
                    'type' => 'stderr',
                    'order' => count($previous_logs) + 1,
                    'timestamp' => Carbon::now('UTC'),
                    'hidden' => false,
                ];
                $deployment->update(['logs' => json_encode($previous_logs, flags: JSON_THROW_ON_ERROR)]);
            }

            $checkCommand = "docker ps -a --filter name={$deployment_uuid} --format '{{.Names}}'";
            $containerExists = instant_remote_process([$checkCommand], $server);

            if ($containerExists && str($containerExists)->trim()->isNotEmpty()) {
                instant_remote_process([$kill_command], $server);
            } else {
                $deployment->addLogEntry('Helper container not yet started. Deployment will be cancelled when job checks status.');
            }

            if ($deployment->current_process_id) {
                try {
                    $processKillCommand = "kill -9 {$deployment->current_process_id}";
                    instant_remote_process([$processKillCommand], $server);
                } catch (\Throwable $e) {
                    Log::error('Unhandled exception in cancel().', ['error' => $e->getMessage()]);
                    // Process might already be gone, that's ok
                }
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in cancel().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        } finally {
            $deployment->update(['current_process_id' => null]);
            next_after_cancel($server);
        }

        return back();
    }

    public function downloadAllLogs(string $project_uuid, string $environment_uuid, string $application_uuid, string $deployment_uuid): HttpResponse|RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid, redirectOnMissing: true);
        if (! $application instanceof Application) {
            return $application;
        }

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', $deployment_uuid)->first();
        if (! $deployment) {
            abort(404);
        }

        $logs = decode_remote_command_output($deployment, includeAll: true)
            ->map(function (array $line) {
                $prefix = '';
                if ($line['hidden']) {
                    $prefix = '[DEBUG] ';
                }
                if (isset($line['command']) && $line['command']) {
                    $prefix .= '[CMD]: ';
                }

                return $line['timestamp'].' '.$prefix.trim($line['line']);
            })
            ->join("\n");

        $content = sanitizeLogsForExport($logs);
        $filename = "deployment-{$deployment_uuid}-all-logs-".now()->format('Y-m-d-H-i-s').'.txt';

        return response($content, 200, [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
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

    /**
     * @return array<string, mixed>
     */
    private function configurationCheckerProps(Application $application): array
    {
        $diff = $application->pendingDeploymentConfigurationDiff();
        $redactEnvironment = ! (bool) auth()->user()?->isAdmin();

        $array = $diff->toArray();
        if ($redactEnvironment) {
            $array['changes'] = collect($array['changes'])->map(function (array $change) {
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

    /**
     * @return array<string, mixed>
     */
    private function deploymentProps(Application $application, ApplicationDeploymentQueue $deployment): array
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
