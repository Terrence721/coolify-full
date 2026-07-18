<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DockerCleanupExecution;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Services\SchedulerLogParser;
use App\Support\DatabaseEngineRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class SettingsScheduledJobsController extends Controller
{
    private const SKIP_DEFAULT_TAKE = 20;

    public function index(Request $request): Response|RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $filterType = (string) $request->query('filterType', 'all');
        $filterDate = (string) $request->query('filterDate', 'last_24h');
        $skipPage = max(0, (int) $request->query('skipPage', 0));

        $executions = $this->getExecutions($filterType, $filterDate);

        $parser = new SchedulerLogParser;
        $allSkips = $parser->getRecentSkips(500);
        $skipTotalCount = $allSkips->count();
        $skipLogs = $this->enrichSkipLogsWithLinks(
            $allSkips->slice($skipPage, self::SKIP_DEFAULT_TAKE)->values()
        );
        $managerRuns = $parser->getRecentRuns(30);

        return Inertia::render('Settings/ScheduledJobs', [
            'filterType' => $filterType,
            'filterDate' => $filterDate,
            'executions' => $executions,
            'managerRuns' => $managerRuns,
            'skipLogs' => $skipLogs,
            'skipTotalCount' => $skipTotalCount,
            'skipDefaultTake' => self::SKIP_DEFAULT_TAKE,
            'skipPage' => $skipPage,
            'skipCurrentPage' => intdiv($skipPage, self::SKIP_DEFAULT_TAKE) + 1,
            'showSkipPrev' => $skipPage > 0,
            'showSkipNext' => ($skipPage + self::SKIP_DEFAULT_TAKE) < $skipTotalCount,
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $skipLogs
     * @return Collection<int, array<string, mixed>>
     */
    private function enrichSkipLogsWithLinks(Collection $skipLogs): Collection
    {
        $taskIds = $skipLogs->where('type', 'task')->pluck('context.task_id')->filter()->unique()->values();
        $backupIds = $skipLogs->where('type', 'backup')->pluck('context.backup_id')->filter()->unique()->values();
        $serverIds = $skipLogs->where('type', 'docker_cleanup')->pluck('context.server_id')->filter()->unique()->values();

        $tasks = $taskIds->isNotEmpty()
            ? ScheduledTask::with(['application.environment.project', 'service.environment.project'])->whereIn('id', $taskIds)->get()->keyBy('id')
            : collect();

        $backups = $backupIds->isNotEmpty()
            ? ScheduledDatabaseBackup::with('database')
                ->whereIn('id', $backupIds)
                ->get()
                ->loadMorph('database', [
                    ServiceDatabase::class => ['service.environment.project'],
                    ...array_fill_keys(DatabaseEngineRegistry::modelClasses(), ['environment.project']),
                ])
                ->keyBy('id')
            : collect();

        $servers = $serverIds->isNotEmpty()
            ? Server::query()->whereIn('id', $serverIds)->get()->keyBy('id')
            : collect();

        return $skipLogs->map(function (array $skip) use ($tasks, $backups, $servers): array {
            $skip['link'] = null;
            $skip['resource_name'] = null;

            if ($skip['type'] === 'task') {
                $task = $tasks->get($skip['context']['task_id'] ?? null);
                if ($task) {
                    $skip['resource_name'] = $skip['context']['task_name'] ?? $task->name;
                    $resource = $task->application ?? $task->service;
                    $environment = $resource?->environment;
                    $project = $environment?->project;
                    if ($project && $environment && $resource) {
                        $routeName = $task->application_id
                            ? 'project.application.scheduled-tasks'
                            : 'project.service.scheduled-tasks';
                        $routeKey = $task->application_id ? 'application_uuid' : 'service_uuid';
                        $skip['link'] = route($routeName, [
                            'project_uuid' => $project->uuid,
                            'environment_uuid' => $environment->uuid,
                            $routeKey => $resource->uuid,
                            'task_uuid' => $task->uuid,
                        ]);
                    }
                }
            } elseif ($skip['type'] === 'backup') {
                $backup = $backups->get($skip['context']['backup_id'] ?? null);
                if ($backup) {
                    $database = $backup->database;
                    $skip['resource_name'] = $database->name ?? 'Database backup';

                    if ($database instanceof ServiceDatabase) {
                        $service = $database->service()->first();
                        $environment = $service?->environment;
                        $project = $environment?->project;
                        if ($project) {
                            $skip['link'] = route('project.service.database.backups', [
                                'project_uuid' => $project->uuid,
                                'environment_uuid' => $environment->uuid,
                                'service_uuid' => $service->uuid,
                                'stack_service_uuid' => $database->uuid,
                            ]);
                        }
                    } else {
                        $environment = $database?->environment;
                        $project = $environment?->project;
                        if ($project && $environment && $database) {
                            $skip['link'] = route('project.database.backup.index', [
                                'project_uuid' => $project->uuid,
                                'environment_uuid' => $environment->uuid,
                                'database_uuid' => $database->uuid,
                            ]);
                        }
                    }
                }
            } elseif ($skip['type'] === 'docker_cleanup') {
                $server = $servers->get($skip['context']['server_id'] ?? null);
                if ($server) {
                    $skip['resource_name'] = $server->name;
                    $skip['link'] = route('server.show', ['server_uuid' => $server->uuid]);
                }
            }

            return $skip;
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function getExecutions(string $filterType, string $filterDate): Collection
    {
        $dateFrom = $this->getDateFrom($filterDate);

        $backups = collect();
        $tasks = collect();
        $cleanups = collect();

        if ($filterType === 'all' || $filterType === 'backup') {
            $backups = $this->getBackupExecutions($dateFrom);
        }

        if ($filterType === 'all' || $filterType === 'task') {
            $tasks = $this->getTaskExecutions($dateFrom);
        }

        if ($filterType === 'all' || $filterType === 'cleanup') {
            $cleanups = $this->getCleanupExecutions($dateFrom);
        }

        return $backups->concat($tasks)->concat($cleanups)
            ->sortByDesc('created_at')
            ->values()
            ->take(100)
            ->map(function (array $execution) {
                $execution['created_at_human'] = $execution['created_at']?->diffForHumans();
                $execution['created_at_formatted'] = $execution['created_at']?->format('M d H:i');
                $execution['duration_seconds'] = ($execution['finished_at'] && $execution['created_at'])
                    ? Carbon::parse($execution['created_at'])->diffInSeconds(Carbon::parse($execution['finished_at']))
                    : null;

                return $execution;
            });
    }

    /**
     * @return Collection<int, array{id: mixed, type: 'backup', status: mixed, resource_name: mixed, resource_type: string|null, server_name: mixed, server_id: mixed, created_at: mixed, finished_at: mixed, message: mixed, size: mixed}>
     */
    private function getBackupExecutions(?Carbon $dateFrom): Collection
    {
        $query = ScheduledDatabaseBackupExecution::with(['scheduledDatabaseBackup.database', 'scheduledDatabaseBackup.team'])
            ->where('status', 'failed')
            ->when($dateFrom, fn ($q) => $q->where('created_at', '>=', $dateFrom))
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return $query->map(function ($execution) {
            $backup = $execution->scheduledDatabaseBackup;
            $database = data_get($backup, 'database');
            $server = $backup?->server();

            return [
                'id' => $execution->id,
                'type' => 'backup',
                'status' => $execution->status ?? 'unknown',
                'resource_name' => data_get($database, 'name', 'Deleted database'),
                'resource_type' => $database ? class_basename($database) : null,
                'server_name' => $server->name ?? 'Unknown',
                'server_id' => $server?->id,
                'created_at' => $execution->created_at,
                'finished_at' => $execution->updated_at,
                'message' => $execution->message,
                'size' => $execution->size ?? null,
            ];
        });
    }

    /**
     * @return Collection<int, array{id: mixed, type: 'task', status: mixed, resource_name: mixed, resource_type: string|null, server_name: mixed, server_id: mixed, created_at: mixed, finished_at: mixed, message: mixed, size: null}>
     */
    private function getTaskExecutions(?Carbon $dateFrom): Collection
    {
        $query = ScheduledTaskExecution::with(['scheduledTask.application', 'scheduledTask.service'])
            ->where('status', 'failed')
            ->when($dateFrom, fn ($q) => $q->where('created_at', '>=', $dateFrom))
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return $query->map(function ($execution) {
            $task = $execution->scheduledTask;
            $resource = data_get($task, 'application') ?? data_get($task, 'service');
            $server = $task?->server();

            return [
                'id' => $execution->id,
                'type' => 'task',
                'status' => $execution->status ?? 'unknown',
                'resource_name' => data_get($task, 'name', 'Deleted task'),
                'resource_type' => $resource ? class_basename($resource) : null,
                'server_name' => data_get($server, 'name', 'Unknown'),
                'server_id' => data_get($server, 'id'),
                'created_at' => $execution->created_at,
                'finished_at' => $execution->finished_at,
                'message' => $execution->message,
                'size' => null,
            ];
        });
    }

    /**
     * @return Collection<int, array{id: mixed, type: 'cleanup', status: mixed, resource_name: mixed, resource_type: 'Server', server_name: mixed, server_id: mixed, created_at: mixed, finished_at: mixed, message: mixed, size: null}>
     */
    private function getCleanupExecutions(?Carbon $dateFrom): Collection
    {
        $query = DockerCleanupExecution::with(['server'])
            ->where('status', 'failed')
            ->when($dateFrom, fn ($q) => $q->where('created_at', '>=', $dateFrom))
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return $query->map(function ($execution) {
            $server = $execution->server;

            return [
                'id' => $execution->id,
                'type' => 'cleanup',
                'status' => $execution->status ?? 'unknown',
                'resource_name' => data_get($server, 'name', 'Deleted server'),
                'resource_type' => 'Server',
                'server_name' => data_get($server, 'name', 'Unknown'),
                'server_id' => data_get($server, 'id'),
                'created_at' => $execution->created_at,
                'finished_at' => $execution->finished_at ?? $execution->updated_at,
                'message' => $execution->message,
                'size' => null,
            ];
        });
    }

    private function getDateFrom(string $filterDate): ?Carbon
    {
        return match ($filterDate) {
            'last_24h' => now()->subDay(),
            'last_7d' => now()->subWeek(),
            'last_30d' => now()->subMonth(),
            default => null,
        };
    }
}
