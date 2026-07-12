<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\StandaloneDatabaseInstance;
use App\Http\Controllers\Concerns\BuildsTerminalCommand;
use App\Http\Controllers\Concerns\ResolvesProjectResources;
use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Support\ServerChromeData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * React port of App\Livewire\Project\Shared\ExecuteContainerCommand — a resource-scoped
 * terminal picker (application/database/service/server) that resolves the servers/containers
 * reachable for a given resource, then hands a selected one off to TerminalWindow.jsx (the
 * same component the standalone /terminal page uses). The SSH-command-building step reuses
 * BuildsTerminalCommand, the same logic TerminalController::connect() already used.
 */
class ExecuteContainerCommandController extends Controller
{
    use AuthorizesRequests;
    use BuildsTerminalCommand;
    use ResolvesProjectResources;

    public function application(string $project_uuid, string $environment_uuid, string $application_uuid): Response|RedirectResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if ($application instanceof RedirectResponse) {
            return $application;
        }

        $this->authorize('view', $application);

        [, $containers] = $this->discoverForApplication($application);
        $parameters = compact('project_uuid', 'environment_uuid', 'application_uuid');

        return Inertia::render('Project/Shared/Command', [
            'type' => 'application',
            'title' => $application->name,
            'application' => ['uuid' => $application->uuid, 'name' => $application->name],
            'parameters' => $parameters,
            'containers' => $this->containerProps($containers),
            'terminalConfig' => $this->terminalConfig(),
            'connectUrl' => route('project.application.command.connect', $parameters),
        ]);
    }

    public function database(string $project_uuid, string $environment_uuid, string $database_uuid): Response|RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if ($database instanceof RedirectResponse) {
            return $database;
        }

        $this->authorize('view', $database);

        [, $containers] = $this->discoverForDatabase($database);
        $parameters = compact('project_uuid', 'environment_uuid', 'database_uuid');

        return Inertia::render('Project/Shared/Command', [
            'type' => 'database',
            'title' => $database->name,
            'parameters' => $parameters,
            'containers' => $this->containerProps($containers),
            'terminalConfig' => $this->terminalConfig(),
            'connectUrl' => route('project.database.command.connect', $parameters),
        ]);
    }

    public function service(string $project_uuid, string $environment_uuid, string $service_uuid): Response|RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if ($service instanceof RedirectResponse) {
            return $service;
        }

        $this->authorize('view', $service);

        [, $containers] = $this->discoverForService($service);
        $parameters = compact('project_uuid', 'environment_uuid', 'service_uuid');

        return Inertia::render('Project/Shared/Command', [
            'type' => 'service',
            'title' => $service->name,
            'service' => ['uuid' => $service->uuid, 'name' => $service->name],
            'parameters' => $parameters,
            'containers' => $this->containerProps($containers),
            'terminalConfig' => $this->terminalConfig(),
            'connectUrl' => route('project.service.command.connect', $parameters),
        ]);
    }

    public function server(string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        return Inertia::render('Server/Command', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'isFunctional' => $server->isFunctional(),
            'isTerminalEnabled' => $server->isTerminalEnabled(),
            'terminalConfig' => $this->terminalConfig(),
            'connectUrl' => route('server.command.connect', ['server_uuid' => $server->uuid]),
        ]);
    }

    public function connectApplication(string $project_uuid, string $environment_uuid, string $application_uuid, Request $request): JsonResponse
    {
        $application = $this->resolveApplication($project_uuid, $environment_uuid, $application_uuid);
        if ($application instanceof RedirectResponse) {
            return response()->json(['error' => 'Application not found.'], 404);
        }

        $this->authorize('view', $application);

        [, $containers] = $this->discoverForApplication($application);

        return $this->respondWithCommand($request, $containers);
    }

    public function connectDatabase(string $project_uuid, string $environment_uuid, string $database_uuid, Request $request): JsonResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if ($database instanceof RedirectResponse) {
            return response()->json(['error' => 'Database not found.'], 404);
        }

        $this->authorize('view', $database);

        [, $containers] = $this->discoverForDatabase($database);

        return $this->respondWithCommand($request, $containers);
    }

    public function connectService(string $project_uuid, string $environment_uuid, string $service_uuid, Request $request): JsonResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if ($service instanceof RedirectResponse) {
            return response()->json(['error' => 'Service not found.'], 404);
        }

        $this->authorize('view', $service);

        [, $containers] = $this->discoverForService($service);

        return $this->respondWithCommand($request, $containers);
    }

    public function connectServer(string $server_uuid): JsonResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->first();
        if (! $server) {
            return response()->json(['error' => 'Server not found.'], 404);
        }

        $result = $this->resolveTerminalCommand($server, null);
        if (isset($result['error'])) {
            return response()->json(array_diff_key($result, ['status' => true]), $result['status']);
        }

        return response()->json(['command' => $result['command']]);
    }

    /**
     * @param  Collection<int, array{server: Server, name: string}>  $containers
     */
    private function respondWithCommand(Request $request, Collection $containers): JsonResponse
    {
        $selectedContainer = (string) $request->input('selected_container');
        if ($selectedContainer === '' || $selectedContainer === 'default') {
            return response()->json(['error' => 'Please select a container.'], 422);
        }

        $container = $containers->firstWhere('name', $selectedContainer);
        if ($container === null) {
            return response()->json(['error' => 'Container not found.'], 404);
        }

        $result = $this->resolveTerminalCommand($container['server'], $selectedContainer);
        if (isset($result['error'])) {
            return response()->json(array_diff_key($result, ['status' => true]), $result['status']);
        }

        return response()->json(['command' => $result['command']]);
    }

    /**
     * @return array{0: Collection<int, Server>, 1: Collection<int, array{server: Server, name: string}>}
     */
    private function discoverForApplication(Application $application): array
    {
        $servers = collect();
        if ($application->destination->server->isFunctional()) {
            $servers->push($application->destination->server);
        }
        foreach ($application->additional_servers as $server) {
            if ($server->isFunctional()) {
                $servers->push($server);
            }
        }

        $containers = collect();
        foreach ($servers as $server) {
            if ($server->isSwarm()) {
                $containerList = collect([['Names' => "{$application->uuid}_{$application->uuid}", 'State' => 'running']]);
            } else {
                $containerList = getCurrentApplicationContainerStatus($server, $application->id, includePullrequests: true);
            }
            foreach ($containerList as $rawContainer) {
                if (data_get($rawContainer, 'State') === 'running' && $server->isTerminalEnabled()) {
                    $containers->push(['server' => $server, 'name' => data_get($rawContainer, 'Names')]);
                }
            }
        }

        return [
            $servers->sortByDesc(fn (Server $server) => $server->isTerminalEnabled())->values(),
            $containers->sortBy('name')->values(),
        ];
    }

    /**
     * @param  Model&StandaloneDatabaseInstance  $database
     * @return array{0: Collection<int, Server>, 1: Collection<int, array{server: Server, name: string}>}
     */
    private function discoverForDatabase(Model $database): array
    {
        $servers = collect();
        $containers = collect();

        $server = $database->destination?->server;
        if ($server && $server->isFunctional()) {
            $servers->push($server);
            if ($database->isRunning() && $server->isTerminalEnabled()) {
                $containers->push(['server' => $server, 'name' => $database->uuid]);
            }
        }

        return [$servers, $containers];
    }

    /**
     * @return array{0: Collection<int, Server>, 1: Collection<int, array{server: Server, name: string}>}
     */
    private function discoverForService(Service $service): array
    {
        $servers = collect();
        $containers = collect();

        $server = $service->server;
        if ($server && $server->isFunctional()) {
            $servers->push($server);
            foreach ($service->applications()->get() as $application) {
                if ($application->isRunning() && $server->isTerminalEnabled()) {
                    $containers->push(['server' => $server, 'name' => "{$application->name}-{$service->uuid}"]);
                }
            }
            foreach ($service->databases()->get() as $database) {
                if ($database->isRunning() && $server->isTerminalEnabled()) {
                    $containers->push(['server' => $server, 'name' => "{$database->name}-{$service->uuid}"]);
                }
            }
        }

        return [$servers, $containers->sortBy('name')->values()];
    }

    /**
     * @param  Collection<int, array{server: Server, name: string}>  $containers
     * @return array<int, array<string, mixed>>
     */
    private function containerProps(Collection $containers): array
    {
        return $containers->map(fn (array $container) => [
            'name' => $container['name'],
            'serverName' => $container['server']->name,
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function terminalConfig(): array
    {
        return [
            'protocol' => config('constants.terminal.protocol'),
            'host' => config('constants.terminal.host'),
            'port' => config('constants.terminal.port'),
        ];
    }
}
