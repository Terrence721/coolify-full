<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Docker\GetContainersStatus;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Enums\ProcessStatus;
use App\Http\Controllers\Concerns\ManagesScheduledDatabaseBackups;
use App\Jobs\DatabaseBackupJob;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Service;
use App\Models\ServiceDatabase;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class ProjectServiceDatabaseBackupController extends Controller
{
    use AuthorizesRequests;
    use ManagesScheduledDatabaseBackups;

    private const DEFAULT_TAKE = 10;

    public function index(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): Response|RedirectResponse
    {
        $result = $this->resolve($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        if ($result instanceof RedirectResponse) {
            return $result;
        }
        [$service, $serviceDatabase] = $result;

        $parameters = compact('project_uuid', 'environment_uuid', 'service_uuid', 'stack_service_uuid');

        if ($serviceDatabase->is_migrated && blank($serviceDatabase->custom_type)) {
            return Inertia::render('Project/Service/DatabaseBackups', [
                'service' => $this->serviceProps($service),
                'serviceDatabase' => $this->serviceDatabaseProps($serviceDatabase),
                'configurationChecker' => $this->configurationCheckerProps($service),
                'needsCustomType' => true,
                'parameters' => $parameters,
                'urls' => $this->headingUrls($parameters),
                'setTypeUrl' => route('project.service.database.backups.set-type', $parameters),
            ]);
        }

        $backups = $serviceDatabase->scheduledBackups->sortByDesc('created_at')->values();
        $selectedBackupId = $request->query('selectedBackupId') ? (int) $request->query('selectedBackupId') : null;
        $selectedBackup = $selectedBackupId ? $backups->firstWhere('id', $selectedBackupId) : null;
        if (! $selectedBackup) {
            $selectedBackupId = null;
        }

        $skip = max(0, (int) $request->query('skip', 0));
        $executions = collect();
        $executionsCount = 0;
        if ($selectedBackup) {
            ['executions' => $executions, 'count' => $executionsCount] = $selectedBackup->executionsPaginated($skip, self::DEFAULT_TAKE);
        }

        return Inertia::render('Project/Service/DatabaseBackups', [
            'service' => $this->serviceProps($service),
            'serviceDatabase' => $this->serviceDatabaseProps($serviceDatabase),
            'configurationChecker' => $this->configurationCheckerProps($service),
            'needsCustomType' => false,
            'scheduledBackups' => $backups->map(fn (ScheduledDatabaseBackup $backup) => $this->backupCardProps($backup, $selectedBackupId))->values(),
            'selectedBackupId' => $selectedBackupId,
            'selectedBackup' => $selectedBackup ? $this->backupEditProps($selectedBackup) : null,
            's3Storages' => $this->s3StorageOptions(currentTeam()->id),
            'executions' => $selectedBackup ? $executions->map(fn (ScheduledDatabaseBackupExecution $execution) => $this->executionProps(
                $execution,
                route('project.service.database.backups.execution.destroy', [...$parameters, 'backup_id' => $selectedBackup->id, 'execution_id' => $execution->id]),
                route('download.backup', ['executionId' => $execution->id]),
            )) : [],
            'executionsCount' => $executionsCount,
            'skip' => $skip,
            'defaultTake' => self::DEFAULT_TAKE,
            'currentPage' => intdiv($skip, self::DEFAULT_TAKE) + 1,
            'showNext' => $executions->count() > 0 && $executions->count() >= self::DEFAULT_TAKE,
            'showPrev' => $skip > 0,
            'parameters' => $parameters,
            'urls' => [
                ...$this->headingUrls($parameters),
                'store' => route('project.service.database.backups.store', $parameters),
                'update' => $selectedBackup ? route('project.service.database.backups.update', [...$parameters, 'backup_id' => $selectedBackup->id]) : null,
                'destroy' => $selectedBackup ? route('project.service.database.backups.destroy', [...$parameters, 'backup_id' => $selectedBackup->id]) : null,
                'backupNow' => $selectedBackup ? route('project.service.database.backups.backup-now', [...$parameters, 'backup_id' => $selectedBackup->id]) : null,
                'cleanupFailed' => $selectedBackup ? route('project.service.database.backups.cleanup-failed', [...$parameters, 'backup_id' => $selectedBackup->id]) : null,
                'cleanupDeleted' => $selectedBackup ? route('project.service.database.backups.cleanup-deleted', [...$parameters, 'backup_id' => $selectedBackup->id]) : null,
            ],
        ]);
    }

    public function setType(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        $result = $this->resolve($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        if ($result instanceof RedirectResponse) {
            return $result;
        }
        [, $serviceDatabase] = $result;

        $this->authorize('update', $serviceDatabase);

        $validated = Validator::make($request->all(), [
            'custom_type' => 'required|string|in:mysql,mariadb,postgresql,mongodb',
        ])->validate();

        $serviceDatabase->custom_type = $validated['custom_type'];
        $serviceDatabase->save();

        return back()->with('success', 'Database type set.');
    }

    public function store(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        $result = $this->resolve($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        if ($result instanceof RedirectResponse) {
            return $result;
        }
        [, $serviceDatabase] = $result;

        $this->authorize('manageBackups', $serviceDatabase);

        $error = $this->createBackupSchedule($request, $serviceDatabase, currentTeam()->id);
        if ($error) {
            return back()->with('error', $error);
        }

        return back()->with('success', 'Scheduled backup created.');
    }

    public function update(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid, int $backup_id): RedirectResponse
    {
        $result = $this->resolve($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        if ($result instanceof RedirectResponse) {
            return $result;
        }
        [, $serviceDatabase] = $result;

        $backup = $this->resolveBackup($serviceDatabase, $backup_id);
        if ($backup instanceof RedirectResponse) {
            return $backup;
        }

        $this->authorize('manageBackups', $serviceDatabase);

        $error = $this->applyBackupScheduleUpdate($request, $backup, currentTeam()->id);
        if ($error) {
            return back()->with('error', $error);
        }

        return back()->with('success', 'Backup updated successfully.');
    }

    public function destroy(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid, int $backup_id): RedirectResponse
    {
        $result = $this->resolve($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        if ($result instanceof RedirectResponse) {
            return $result;
        }
        [, $serviceDatabase] = $result;

        $backup = $this->resolveBackup($serviceDatabase, $backup_id);
        if ($backup instanceof RedirectResponse) {
            return $backup;
        }

        $this->authorize('manageBackups', $serviceDatabase);

        if (! verifyPasswordConfirmation($request->input('password'))) {
            return back()->with('error', 'The provided password is incorrect.');
        }

        $this->deleteBackupScheduleFiles($request, $backup, $serviceDatabase->service->server);

        return back()->with('success', 'Scheduled backup deleted.');
    }

    public function backupNow(string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid, int $backup_id): RedirectResponse
    {
        $result = $this->resolve($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        if ($result instanceof RedirectResponse) {
            return $result;
        }
        [, $serviceDatabase] = $result;

        $backup = $this->resolveBackup($serviceDatabase, $backup_id);
        if ($backup instanceof RedirectResponse) {
            return $backup;
        }

        $this->authorize('manageBackups', $serviceDatabase);

        DatabaseBackupJob::dispatch($backup);

        return back()->with('success', 'Backup queued. It will be available in a few minutes.');
    }

    public function cleanupFailedExecutions(string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid, int $backup_id): RedirectResponse
    {
        $result = $this->resolve($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        if ($result instanceof RedirectResponse) {
            return $result;
        }
        [, $serviceDatabase] = $result;

        $backup = $this->resolveBackup($serviceDatabase, $backup_id);
        if ($backup instanceof RedirectResponse) {
            return $backup;
        }

        $this->authorize('manageBackups', $serviceDatabase);

        $this->cleanupFailedBackupExecutions($backup);

        return back()->with('success', 'Failed backups cleaned up.');
    }

    public function cleanupDeletedExecutions(string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid, int $backup_id): RedirectResponse
    {
        $result = $this->resolve($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        if ($result instanceof RedirectResponse) {
            return $result;
        }
        [, $serviceDatabase] = $result;

        $backup = $this->resolveBackup($serviceDatabase, $backup_id);
        if ($backup instanceof RedirectResponse) {
            return $backup;
        }

        $this->authorize('manageBackups', $serviceDatabase);

        $deletedCount = $this->cleanupDeletedBackupExecutions($backup);
        if ($deletedCount === 0) {
            return back()->with('info', 'No backup entries found that are deleted from local storage.');
        }

        return back()->with('success', "Cleaned up {$deletedCount} backup entries deleted from local storage.");
    }

    public function destroyExecution(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid, int $backup_id, int $execution_id): RedirectResponse
    {
        $result = $this->resolve($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        if ($result instanceof RedirectResponse) {
            return $result;
        }
        [, $serviceDatabase] = $result;

        $backup = $this->resolveBackup($serviceDatabase, $backup_id);
        if ($backup instanceof RedirectResponse) {
            return $backup;
        }

        $this->authorize('manageBackups', $serviceDatabase);

        if (! verifyPasswordConfirmation($request->input('password'))) {
            return back()->with('error', 'The provided password is incorrect.');
        }

        $error = $this->deleteBackupExecution($request, $backup, $execution_id, $serviceDatabase->service->server);
        if ($error) {
            return back()->with('error', $error);
        }

        return back()->with('success', 'Backup deleted.');
    }

    public function start(string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        $result = $this->resolve($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        if ($result instanceof RedirectResponse) {
            return $result;
        }
        [$service] = $result;

        $this->authorize('deploy', $service);

        $activity = StartService::run($service, pullLatestImages: true);

        return back()->with(['activityId' => $activity->id, 'activityContext' => 'service']);
    }

    public function forceDeploy(string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        $result = $this->resolve($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        if ($result instanceof RedirectResponse) {
            return $result;
        }
        [$service] = $result;

        $this->authorize('deploy', $service);

        $inProgressStatuses = [ProcessStatus::IN_PROGRESS->value, ProcessStatus::QUEUED->value];
        Activity::where('properties->type_uuid', $service->uuid)
            ->whereIn('properties->status', $inProgressStatuses)
            ->get()
            ->each(function (Activity $activity) {
                $activity->properties->status = ProcessStatus::ERROR->value;
                $activity->save();
            });

        $activity = StartService::run($service, pullLatestImages: true, stopBeforeStart: true);

        return back()->with(['activityId' => $activity->id, 'activityContext' => 'service']);
    }

    public function restart(string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        $result = $this->resolve($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        if ($result instanceof RedirectResponse) {
            return $result;
        }
        [$service] = $result;

        $this->authorize('deploy', $service);

        if ($this->isDeploymentInProgress($service)) {
            return back()->with('error', 'There is a deployment in progress.');
        }

        $activity = StartService::run($service, stopBeforeStart: true);

        return back()->with(['activityId' => $activity->id, 'activityContext' => 'service']);
    }

    public function stop(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        $result = $this->resolve($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        if ($result instanceof RedirectResponse) {
            return $result;
        }
        [$service] = $result;

        $this->authorize('stop', $service);

        StopService::dispatch($service, false, $request->boolean('docker_cleanup', true));

        return back()->with('info', 'Gracefully stopping service. It could take a while depending on the service.');
    }

    private function isDeploymentInProgress(Service $service): bool
    {
        $activity = Activity::where('properties->type_uuid', $service->uuid)->latest()->first();
        $status = data_get($activity, 'properties.status');

        return $status === ProcessStatus::QUEUED->value || $status === ProcessStatus::IN_PROGRESS->value;
    }

    public function checkStatus(string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        $result = $this->resolve($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        if ($result instanceof RedirectResponse) {
            return $result;
        }
        [$service] = $result;

        if (! $service->server->isFunctional()) {
            return back()->with('error', 'Server is not functional.');
        }

        GetContainersStatus::dispatch($service->server);

        return back();
    }

    /**
     * @return array{0: Service, 1: ServiceDatabase}|RedirectResponse
     */
    private function resolve(string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): array|RedirectResponse
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

        $this->authorize('view', $service);

        $serviceDatabase = $service->databases()->whereUuid($stack_service_uuid)->first();
        if (! $serviceDatabase) {
            return redirect()->route('project.service.configuration', compact('project_uuid', 'environment_uuid', 'service_uuid'));
        }

        if (! $serviceDatabase->isBackupSolutionAvailable() && ! $serviceDatabase->is_migrated) {
            return redirect()->route('project.service.index', compact('project_uuid', 'environment_uuid', 'service_uuid', 'stack_service_uuid'));
        }

        return [$service, $serviceDatabase];
    }

    private function resolveBackup(ServiceDatabase $serviceDatabase, int $backupId): ScheduledDatabaseBackup|RedirectResponse
    {
        $backup = $serviceDatabase->scheduledBackups()->find($backupId);
        if (! $backup) {
            return back()->with('error', 'Backup schedule not found.');
        }

        return $backup;
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, string>
     */
    private function headingUrls(array $parameters): array
    {
        return [
            'start' => route('project.service.database.backups.start', $parameters),
            'forceDeploy' => route('project.service.database.backups.force-deploy', $parameters),
            'restart' => route('project.service.database.backups.restart', $parameters),
            'stop' => route('project.service.database.backups.stop', $parameters),
            'checkStatus' => route('project.service.database.backups.check-status', $parameters),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceProps(Service $service): array
    {
        return [
            'uuid' => $service->uuid,
            'name' => $service->name,
            'status' => $service->status,
            'isDeployable' => $service->isDeployable,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceDatabaseProps(ServiceDatabase $serviceDatabase): array
    {
        return [
            'uuid' => $serviceDatabase->uuid,
            'name' => $serviceDatabase->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function configurationCheckerProps(Service $service): array
    {
        return [
            'isConfigurationChanged' => $service->isConfigurationChanged(),
            'isExited' => str($service->status)->contains('exited'),
            'configHash' => $service->config_hash,
            'diff' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function backupCardProps(ScheduledDatabaseBackup $backup, ?int $selectedBackupId): array
    {
        $latestLog = $backup->latest_log;
        $status = $latestLog?->status;

        $timingText = null;
        $sizeText = null;
        if ($latestLog) {
            if ($status === 'running') {
                $timingText = 'Running for '.calculateDuration($latestLog->created_at, now());
            } else {
                $timingText = Carbon::parse($latestLog->finished_at)->diffForHumans()
                    .' ('.calculateDuration($latestLog->created_at, $latestLog->finished_at).')'
                    .' • '.Carbon::parse($latestLog->finished_at)->format('M j, H:i');
            }
            if ($status === 'success' && $latestLog->size > 0) {
                $sizeText = formatBytes($latestLog->size);
            }
        }

        $totalCount = $backup->executions()->count();
        $successCount = $backup->executions()->where('status', 'success')->count();

        return [
            'id' => $backup->id,
            'frequency' => $backup->frequency,
            'saveS3' => (bool) $backup->save_s3,
            'status' => $status,
            'timingText' => $timingText,
            'sizeText' => $sizeText,
            'totalExecutions' => $totalCount,
            'successRate' => $totalCount > 0 ? (int) round(($successCount / $totalCount) * 100) : null,
            'selected' => $backup->id === $selectedBackupId,
        ];
    }
}
