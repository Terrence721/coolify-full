<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Docker\GetContainersStatus;
use App\Models\StandaloneDatabaseInstance;
use App\Http\Controllers\Concerns\ManagesScheduledDatabaseBackups;
use App\Jobs\DatabaseBackupJob;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Support\DatabaseEngineRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class ProjectDatabaseBackupController extends Controller
{
    use AuthorizesRequests;
    use ManagesScheduledDatabaseBackups;

    public function index(string $project_uuid, string $environment_uuid, string $database_uuid): Response|RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof StandaloneDatabaseInstance) {
            return $database;
        }

        if (! (DatabaseEngineRegistry::forInstance($database)->supportsBackup ?? false)) {
            return redirect()->route('project.database.configuration', compact('project_uuid', 'environment_uuid', 'database_uuid'));
        }

        $parameters = compact('project_uuid', 'environment_uuid', 'database_uuid');

        return Inertia::render('Project/Database/Backup/Index', [
            'database' => [
                'uuid' => $database->uuid,
                'name' => $database->name,
                'status' => $database->status,
            ],
            'heading' => $this->headingProps($database, $parameters),
            'configurationChecker' => $this->configurationCheckerProps($database),
            'scheduledBackups' => $database->scheduledBackups->sortByDesc('created_at')->values()->map(
                fn (ScheduledDatabaseBackup $backup) => $this->backupProps($backup, $parameters)
            ),
            's3Storages' => $this->s3StorageOptions(currentTeam()->id),
            'canUpdate' => auth()->user()?->can('update', $database) ?? false,
            'urls' => [
                'store' => route('project.database.backup.store', $parameters),
                'start' => route('project.database.start', $parameters),
                'stop' => route('project.database.stop', $parameters),
                'restart' => route('project.database.restart', $parameters),
                'checkStatus' => route('project.database.check-status', $parameters),
            ],
        ]);
    }

    public function store(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof StandaloneDatabaseInstance) {
            return $database;
        }

        $this->authorize('manageBackups', $database);

        $error = $this->createBackupSchedule($request, $database, currentTeam()->id);
        if ($error) {
            return back()->with('error', $error);
        }

        return back()->with('success', 'Scheduled backup created.');
    }

    public function execution(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid, string $backup_uuid): Response|RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof StandaloneDatabaseInstance) {
            return $database;
        }

        $backup = $this->resolveBackup($database, $backup_uuid);
        if (! $backup instanceof ScheduledDatabaseBackup) {
            return $backup;
        }

        $parameters = compact('project_uuid', 'environment_uuid', 'database_uuid');
        $skip = max(0, (int) $request->query('skip', 0));
        $defaultTake = 10;
        ['executions' => $executions, 'count' => $count] = $backup->executionsPaginated($skip, $defaultTake);

        return Inertia::render('Project/Database/Backup/Execution', [
            'database' => [
                'uuid' => $database->uuid,
                'name' => $database->name,
                'status' => $database->status,
            ],
            'heading' => $this->headingProps($database, $parameters),
            'configurationChecker' => $this->configurationCheckerProps($database),
            'backup' => $this->backupEditProps($backup),
            's3Storages' => $this->s3StorageOptions(currentTeam()->id),
            'executions' => $executions->map(fn (ScheduledDatabaseBackupExecution $execution) => $this->executionProps(
                $execution,
                route('project.database.backup.execution.destroy', [...$parameters, 'backup_uuid' => $backup_uuid, 'execution_id' => $execution->id]),
                route('download.backup', ['executionId' => $execution->id]),
            )),
            'executionsCount' => $count,
            'skip' => $skip,
            'defaultTake' => $defaultTake,
            'currentPage' => intdiv($skip, $defaultTake) + 1,
            'showNext' => $executions->count() > 0 && $executions->count() >= $defaultTake,
            'showPrev' => $skip > 0,
            'canManageBackups' => auth()->user()?->can('manageBackups', $database) ?? false,
            'urls' => [
                'update' => route('project.database.backup.update', [...$parameters, 'backup_uuid' => $backup_uuid]),
                'destroy' => route('project.database.backup.destroy', [...$parameters, 'backup_uuid' => $backup_uuid]),
                'backupNow' => route('project.database.backup.backup-now', [...$parameters, 'backup_uuid' => $backup_uuid]),
                'cleanupFailed' => route('project.database.backup.cleanup-failed', [...$parameters, 'backup_uuid' => $backup_uuid]),
                'cleanupDeleted' => route('project.database.backup.cleanup-deleted', [...$parameters, 'backup_uuid' => $backup_uuid]),
            ],
        ]);
    }

    public function updateBackupSchedule(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid, string $backup_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof StandaloneDatabaseInstance) {
            return $database;
        }

        $backup = $this->resolveBackup($database, $backup_uuid, redirectOnMissing: true);
        if (! $backup instanceof ScheduledDatabaseBackup) {
            return $backup;
        }

        $this->authorize('manageBackups', $database);

        $error = $this->applyBackupScheduleUpdate($request, $backup, currentTeam()->id);
        if ($error) {
            return back()->with('error', $error);
        }

        return back()->with('success', 'Backup updated successfully.');
    }

    public function destroyBackupSchedule(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid, string $backup_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof StandaloneDatabaseInstance) {
            return $database;
        }

        $backup = $this->resolveBackup($database, $backup_uuid, redirectOnMissing: true);
        if (! $backup instanceof ScheduledDatabaseBackup) {
            return $backup;
        }

        $this->authorize('manageBackups', $database);

        if (! verifyPasswordConfirmation($request->input('password'))) {
            return back()->with('error', 'The provided password is incorrect.');
        }

        $this->deleteBackupScheduleFiles($request, $backup, $database->destination?->server);

        return redirect()->route('project.database.backup.index', compact('project_uuid', 'environment_uuid', 'database_uuid'))
            ->with('success', 'Scheduled backup deleted.');
    }

    public function backupNow(string $project_uuid, string $environment_uuid, string $database_uuid, string $backup_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof StandaloneDatabaseInstance) {
            return $database;
        }

        $backup = $this->resolveBackup($database, $backup_uuid, redirectOnMissing: true);
        if (! $backup instanceof ScheduledDatabaseBackup) {
            return $backup;
        }

        $this->authorize('manageBackups', $database);

        DatabaseBackupJob::dispatch($backup);

        return back()->with('success', 'Backup queued. It will be available in a few minutes.');
    }

    public function cleanupFailedExecutions(string $project_uuid, string $environment_uuid, string $database_uuid, string $backup_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof StandaloneDatabaseInstance) {
            return $database;
        }

        $backup = $this->resolveBackup($database, $backup_uuid, redirectOnMissing: true);
        if (! $backup instanceof ScheduledDatabaseBackup) {
            return $backup;
        }

        $this->cleanupFailedBackupExecutions($backup);

        return back()->with('success', 'Failed backups cleaned up.');
    }

    public function cleanupDeletedExecutions(string $project_uuid, string $environment_uuid, string $database_uuid, string $backup_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof StandaloneDatabaseInstance) {
            return $database;
        }

        $backup = $this->resolveBackup($database, $backup_uuid, redirectOnMissing: true);
        if (! $backup instanceof ScheduledDatabaseBackup) {
            return $backup;
        }

        $deletedCount = $this->cleanupDeletedBackupExecutions($backup);
        if ($deletedCount === 0) {
            return back()->with('info', 'No backup entries found that are deleted from local storage.');
        }

        return back()->with('success', "Cleaned up {$deletedCount} backup entries deleted from local storage.");
    }

    public function destroyExecution(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid, string $backup_uuid, int $execution_id): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof StandaloneDatabaseInstance) {
            return $database;
        }

        $backup = $this->resolveBackup($database, $backup_uuid, redirectOnMissing: true);
        if (! $backup instanceof ScheduledDatabaseBackup) {
            return $backup;
        }

        $this->authorize('manageBackups', $database);

        if (! verifyPasswordConfirmation($request->input('password'))) {
            return back()->with('error', 'The provided password is incorrect.');
        }

        $error = $this->deleteBackupExecution($request, $backup, $execution_id, $database->destination?->server);
        if ($error) {
            return back()->with('error', $error);
        }

        return back()->with('success', 'Backup deleted.');
    }

    public function start(string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof StandaloneDatabaseInstance) {
            return $database;
        }

        $this->authorize('manage', $database);

        $activity = StartDatabase::run($database);
        if (! $activity instanceof Activity) {
            return back()->with('error', is_string($activity) ? $activity : 'Failed to start database.');
        }

        return back()->with(['activityId' => $activity->id, 'activityContext' => 'database']);
    }

    public function restart(string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof StandaloneDatabaseInstance) {
            return $database;
        }

        $this->authorize('manage', $database);

        $activity = RestartDatabase::run($database);
        if (! $activity instanceof Activity) {
            return back()->with('error', is_string($activity) ? $activity : 'Failed to restart database.');
        }

        return back()->with(['activityId' => $activity->id, 'activityContext' => 'database']);
    }

    public function stop(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof StandaloneDatabaseInstance) {
            return $database;
        }

        $this->authorize('manage', $database);

        StopDatabase::dispatch($database, false, $request->boolean('docker_cleanup', true));

        return back()->with('info', 'Gracefully stopping database.');
    }

    public function checkStatus(string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof StandaloneDatabaseInstance) {
            return $database;
        }

        if (! $database->destination->server->isFunctional()) {
            return back()->with('error', 'Server is not functional.');
        }

        GetContainersStatus::dispatch($database->destination->server);

        return back();
    }

    private function resolveDatabase(string $project_uuid, string $environment_uuid, string $database_uuid, bool $redirectOnMissing = false): StandaloneDatabaseInstance|RedirectResponse
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

    private function resolveBackup(StandaloneDatabaseInstance $database, string $backup_uuid, bool $redirectOnMissing = false): ScheduledDatabaseBackup|RedirectResponse
    {
        $backup = $database->scheduledBackups->where('uuid', $backup_uuid)->first();
        if (! $backup) {
            return redirect()->route('dashboard');
        }

        return $backup;
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function headingProps(StandaloneDatabaseInstance $database, array $parameters): array
    {
        return [
            'parameters' => $parameters,
            'dockerCleanupDefault' => true,
            'isFunctional' => (bool) $database->destination?->server?->isFunctional(),
            'isExited' => str($database->status)->startsWith('exited'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function configurationCheckerProps(StandaloneDatabaseInstance $database): array
    {
        return [
            'isConfigurationChanged' => $database->isConfigurationChanged(),
            'isExited' => str($database->status)->startsWith('exited'),
            'configHash' => $database->config_hash,
            'diff' => [],
        ];
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function backupProps(ScheduledDatabaseBackup $backup, array $parameters): array
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

        return [
            'id' => $backup->id,
            'frequency' => $backup->frequency,
            'saveS3' => (bool) $backup->save_s3,
            'status' => $status,
            'timingText' => $timingText,
            'sizeText' => $sizeText,
            'executeUrl' => route('project.database.backup.execution', [...$parameters, 'backup_uuid' => $backup->uuid]),
        ];
    }
}
