<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\StandaloneDatabaseInstance;
use App\Http\Controllers\Concerns\ManagesServiceLifecycle;
use App\Http\Controllers\Concerns\StreamsContainerLogs;
use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectLogsController extends Controller
{
    use AuthorizesRequests;
    use ManagesServiceLifecycle;
    use StreamsContainerLogs;

    public function application(Request $request, string $project_uuid, string $environment_uuid, string $application_uuid): Response|RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if ($application instanceof RedirectResponse) {
            return $application;
        }

        $this->authorize('view', $application);

        $pullRequestId = $request->query('pull_request_id');

        $servers = collect();
        if ($application->destination->server->isFunctional()) {
            $servers->push($application->destination->server);
        }
        foreach ($application->additional_servers as $server) {
            if ($server->isFunctional()) {
                $servers->push($server);
            }
        }

        $containerGroups = [];
        foreach ($servers as $server) {
            $containerNames = $this->discoverApplicationContainers($server, $application);
            if ($pullRequestId) {
                $containerNames = array_values(array_filter($containerNames, fn (string $name) => str_contains($name, (string) $pullRequestId)));
            }
            $containerGroups[] = [
                'serverName' => $server->name,
                'containers' => $this->buildContainerEntries($request, $server, $containerNames),
            ];
        }

        $lastDeployment = $application->get_last_successful_deployment();
        $parameters = compact('project_uuid', 'environment_uuid', 'application_uuid');

        return Inertia::render('Project/Shared/Logs', [
            'type' => 'application',
            'title' => $application->name,
            'application' => ['uuid' => $application->uuid, 'name' => $application->name],
            'heading' => [
                'lastDeploymentInfo' => trim(str($lastDeployment?->commit)->limit(7).' '.($lastDeployment?->commit_message ?? '')),
                'lastDeploymentLink' => $application->gitCommitLink((string) $lastDeployment?->commit),
            ],
            'headingUrls' => [
                'deploy' => route('project.application.deployment.deploy', $parameters),
                'restart' => route('project.application.deployment.restart', $parameters),
                'stop' => route('project.application.deployment.stop', $parameters),
                'checkStatus' => route('project.application.deployment.check-status', $parameters),
            ],
            'configurationChecker' => $this->applicationConfigurationCheckerProps($application),
            'containerGroups' => $containerGroups,
            'noServerMessage' => 'No functional server found for the application.',
            'parameters' => $parameters,
        ]);
    }

    public function database(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): Response|RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if ($database instanceof RedirectResponse) {
            return $database;
        }

        $this->authorize('view', $database);

        $containerGroups = [];
        $server = $database->destination?->server;
        if ($server && $server->isFunctional()) {
            $containerGroups[] = [
                'serverName' => $server->name,
                'containers' => $this->buildContainerEntries($request, $server, [$database->uuid]),
            ];
        }

        $parameters = compact('project_uuid', 'environment_uuid', 'database_uuid');

        return Inertia::render('Project/Shared/Logs', [
            'type' => 'database',
            'title' => $database->name,
            'isExited' => str($database->status)->contains('exited'),
            'headingUrls' => [
                'start' => route('project.database.start', $parameters),
                'restart' => route('project.database.restart', $parameters),
                'stop' => route('project.database.stop', $parameters),
                'checkStatus' => route('project.database.check-status', $parameters),
            ],
            'databaseHeading' => [
                'parameters' => $parameters,
                'dockerCleanupDefault' => true,
                'isFunctional' => (bool) $server?->isFunctional(),
                'isExited' => str($database->status)->startsWith('exited'),
            ],
            'configurationChecker' => [
                'isConfigurationChanged' => $database->isConfigurationChanged(),
                'isExited' => str($database->status)->startsWith('exited'),
                'configHash' => $database->config_hash,
                'diff' => [],
            ],
            'containerGroups' => $containerGroups,
            'noServerMessage' => 'No functional server found for the database.',
            'parameters' => $parameters,
        ]);
    }

    public function service(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): Response|RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if ($service instanceof RedirectResponse) {
            return $service;
        }

        $this->authorize('view', $service);

        $containerNames = [];
        foreach ($service->applications()->get() as $application) {
            $containerNames[] = "{$application->name}-{$service->uuid}";
        }
        foreach ($service->databases()->get() as $database) {
            $containerNames[] = "{$database->name}-{$service->uuid}";
        }

        $containerGroups = [];
        if ($service->server->isFunctional()) {
            $containerGroups[] = [
                'serverName' => $service->server->name,
                'containers' => $this->buildContainerEntries($request, $service->server, $containerNames),
            ];
        }

        $parameters = compact('project_uuid', 'environment_uuid', 'service_uuid');

        return Inertia::render('Project/Shared/Logs', [
            'type' => 'service',
            'title' => $service->name,
            'isExited' => str($service->status)->contains('exited'),
            'service' => ['uuid' => $service->uuid, 'name' => $service->name, 'status' => $service->status, 'isDeployable' => $service->isDeployable],
            'headingUrls' => [
                'start' => route('project.logs.service.start', $parameters),
                'forceDeploy' => route('project.logs.service.force-deploy', $parameters),
                'restart' => route('project.logs.service.restart', $parameters),
                'stop' => route('project.logs.service.stop', $parameters),
                'checkStatus' => route('project.logs.service.check-status', $parameters),
            ],
            'configurationChecker' => $this->serviceConfigurationCheckerProps($service),
            'containerGroups' => $containerGroups,
            'noServerMessage' => 'No functional server found for the service.',
            'parameters' => $parameters,
        ]);
    }

    public function serviceStart(string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if ($service instanceof RedirectResponse) {
            return $service;
        }

        return $this->startService($service);
    }

    public function serviceForceDeploy(string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if ($service instanceof RedirectResponse) {
            return $service;
        }

        return $this->forceDeployService($service);
    }

    public function serviceRestart(string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if ($service instanceof RedirectResponse) {
            return $service;
        }

        return $this->restartService($service);
    }

    public function serviceStop(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if ($service instanceof RedirectResponse) {
            return $service;
        }

        return $this->stopService($request, $service);
    }

    public function serviceCheckStatus(string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if ($service instanceof RedirectResponse) {
            return $service;
        }

        return $this->checkServiceStatus($service);
    }

    public function downloadLogs(Request $request, string $server_uuid): HttpResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        if (! $server->isFunctional()) {
            abort(404);
        }

        $container = $request->query('container');
        if (! $container || ! ValidationPatterns::isValidContainerName($container)) {
            abort(404);
        }

        $showTimestamps = $request->query('timestamps', '1') !== '0';

        return $this->downloadContainerLogsResponse($server, $container, $showTimestamps, str($container)->beforeLast('-')->slug()->value());
    }

    /**
     * @return array<int, string>
     */
    private function discoverApplicationContainers(Server $server, Application $application): array
    {
        if ($server->isSwarm()) {
            return ["{$application->uuid}_{$application->uuid}"];
        }

        $containers = getCurrentApplicationContainerStatus($server, $application->id, includePullrequests: true);

        return $containers->pluck('Names')->filter()->sort()->values()->all();
    }

    /**
     * @param  array<int, string>  $containerNames
     * @return array<int, array<string, mixed>>
     */
    private function buildContainerEntries(Request $request, Server $server, array $containerNames): array
    {
        return collect($containerNames)->values()->map(function (string $container, int $index) use ($request, $server) {
            $prefix = "c{$index}_";
            $numberOfLines = max(1, min(50000, (int) $request->query("{$prefix}lines", 100)));
            $showTimestamps = $request->query("{$prefix}timestamps", '1') !== '0';

            $rawOutput = $this->fetchContainerLogs($server, $container, $numberOfLines, $showTimestamps);

            return [
                'key' => $container,
                'queryPrefix' => $prefix,
                'displayName' => str($container)->beforeLast('-')->headline(),
                'pullRequest' => str($container)->contains('-pr-')
                    ? 'Pull Request: '.str($container)->afterLast('-pr-')->beforeLast('_')->value()
                    : null,
                'logLines' => $this->parseContainerLogLines($rawOutput, $server),
                'numberOfLines' => $numberOfLines,
                'showTimestamps' => $showTimestamps,
                'urls' => [
                    'downloadAll' => route('project.logs.download', [
                        'server_uuid' => $server->uuid,
                        'container' => $container,
                        'timestamps' => $showTimestamps ? 1 : 0,
                    ]),
                ],
            ];
        })->all();
    }

    private function resolveApplication(string $project_uuid, string $environment_uuid, string $application_uuid): Application|RedirectResponse
    {
        $project = currentTeam()->load(['projects'])->projects->where('uuid', $project_uuid)->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('uuid', $environment_uuid)->first();
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        $application = $environment->applications()->where('uuid', $application_uuid)->first();
        if (! $application) {
            return redirect()->route('dashboard');
        }

        return $application;
    }

    /**
     * @return (Model&StandaloneDatabaseInstance)|RedirectResponse
     */
    private function resolveDatabase(string $project_uuid, string $environment_uuid, string $database_uuid): Model|RedirectResponse
    {
        $project = currentTeam()->load(['projects'])->projects->where('uuid', $project_uuid)->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('uuid', $environment_uuid)->first();
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        $database = $environment->databases()->where('uuid', $database_uuid)->first();
        if (! $database) {
            return redirect()->route('dashboard');
        }

        return $database;
    }

    private function resolveService(string $project_uuid, string $environment_uuid, string $service_uuid): Service|RedirectResponse
    {
        $project = currentTeam()->load(['projects'])->projects->where('uuid', $project_uuid)->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('uuid', $environment_uuid)->first();
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        $service = $environment->services()->whereUuid($service_uuid)->first();
        if (! $service) {
            return redirect()->route('dashboard');
        }

        return $service;
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationConfigurationCheckerProps(Application $application): array
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
}
