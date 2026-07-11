<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Docker\GetContainersStatus;
use App\Contracts\StandaloneDatabaseInstance;
use App\Jobs\DatabaseBackupJob;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Support\DatabaseEngineRegistry;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class ProjectDatabaseBackupController extends Controller
{
    use AuthorizesRequests;

    public function index(string $project_uuid, string $environment_uuid, string $database_uuid): Response|RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        if (! (DatabaseEngineRegistry::forInstance($database)?->supportsBackup ?? false)) {
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
            's3Storages' => currentTeam()->s3s->map(fn (S3Storage $s3) => ['id' => $s3->id, 'name' => $s3->name])->values(),
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
        if (! $database instanceof Model) {
            return $database;
        }

        $this->authorize('manageBackups', $database);

        $validated = Validator::make($request->all(), [
            'frequency' => 'required|string',
            'save_to_s3' => 'required|boolean',
            's3_storage_id' => 'nullable|integer',
        ])->validate();

        if ($validated['save_to_s3']) {
            $s3StorageExists = ! is_null($validated['s3_storage_id'] ?? null)
                && S3Storage::where('team_id', currentTeam()->id)
                    ->where('is_usable', true)
                    ->whereKey($validated['s3_storage_id'])
                    ->exists();

            if (! $s3StorageExists) {
                return back()->with('error', 'Please select a valid S3 storage to enable S3 backups.');
            }
        }

        if (! validate_cron_expression($validated['frequency'])) {
            return back()->with('error', 'Invalid Cron / Human expression.');
        }

        $payload = [
            'enabled' => true,
            'frequency' => $validated['frequency'],
            'save_s3' => $validated['save_to_s3'],
            's3_storage_id' => $validated['s3_storage_id'] ?? null,
            'database_id' => $database->id,
            'database_type' => $database->getMorphClass(),
            'team_id' => currentTeam()->id,
        ];

        if ($database->type() === 'standalone-postgresql') {
            $payload['databases_to_backup'] = $database->postgres_db;
        } elseif ($database->type() === 'standalone-mysql') {
            $payload['databases_to_backup'] = $database->mysql_database;
        } elseif ($database->type() === 'standalone-mariadb') {
            $payload['databases_to_backup'] = $database->mariadb_database;
        }

        ScheduledDatabaseBackup::create($payload);

        return back()->with('success', 'Scheduled backup created.');
    }

    public function execution(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid, string $backup_uuid): Response|RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
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
            's3Storages' => currentTeam()->s3s->map(fn (S3Storage $s3) => ['id' => $s3->id, 'name' => $s3->name])->values(),
            'executions' => $executions->map(fn (ScheduledDatabaseBackupExecution $execution) => $this->executionProps($execution, $parameters, $backup_uuid)),
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
        if (! $database instanceof Model) {
            return $database;
        }

        $backup = $this->resolveBackup($database, $backup_uuid, redirectOnMissing: true);
        if (! $backup instanceof ScheduledDatabaseBackup) {
            return $backup;
        }

        $this->authorize('manageBackups', $database);

        $validated = Validator::make($request->all(), [
            'enabled' => 'required|boolean',
            'frequency' => 'required|string',
            'save_s3' => 'required|boolean',
            'disable_local_backup' => 'required|boolean',
            's3_storage_id' => 'nullable|integer',
            'databases_to_backup' => 'nullable|string',
            'dump_all' => 'required|boolean',
            'timeout' => 'required|integer|min:60|max:36000',
            'database_backup_retention_amount_locally' => 'required|integer|min:0',
            'database_backup_retention_days_locally' => 'required|integer|min:0',
            'database_backup_retention_max_storage_locally' => 'required|numeric|min:0',
            'database_backup_retention_amount_s3' => 'required|integer|min:0',
            'database_backup_retention_days_s3' => 'required|integer|min:0',
            'database_backup_retention_max_storage_s3' => 'required|numeric|min:0',
        ])->validate();

        if (! validate_cron_expression($validated['frequency'])) {
            return back()->with('error', 'Invalid Cron / Human expression');
        }

        if (filled($validated['databases_to_backup'] ?? null)) {
            try {
                validateDatabasesBackupInput($validated['databases_to_backup']);
            } catch (\Throwable $e) {
                return back()->with('error', $e->getMessage());
            }
        }

        $availableS3Ids = currentTeam()->s3s->pluck('id');
        $saveS3 = $validated['save_s3'];
        $s3StorageId = $validated['s3_storage_id'] ?? null;
        if ($saveS3 && ! $availableS3Ids->contains($s3StorageId)) {
            $saveS3 = false;
            $s3StorageId = null;
        }

        $disableLocalBackup = $validated['disable_local_backup'];
        if ($disableLocalBackup && ! $saveS3) {
            $disableLocalBackup = false;
        }

        $backup->update([
            'enabled' => $validated['enabled'],
            'frequency' => $validated['frequency'],
            'database_backup_retention_amount_locally' => $validated['database_backup_retention_amount_locally'],
            'database_backup_retention_days_locally' => $validated['database_backup_retention_days_locally'],
            'database_backup_retention_max_storage_locally' => $validated['database_backup_retention_max_storage_locally'],
            'database_backup_retention_amount_s3' => $validated['database_backup_retention_amount_s3'],
            'database_backup_retention_days_s3' => $validated['database_backup_retention_days_s3'],
            'database_backup_retention_max_storage_s3' => $validated['database_backup_retention_max_storage_s3'],
            'save_s3' => $saveS3,
            'disable_local_backup' => $disableLocalBackup,
            's3_storage_id' => $s3StorageId,
            'databases_to_backup' => $validated['databases_to_backup'] ?? null,
            'dump_all' => $validated['dump_all'],
            'timeout' => $validated['timeout'],
        ]);

        return back()->with('success', 'Backup updated successfully.');
    }

    public function destroyBackupSchedule(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid, string $backup_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof Model) {
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

        $server = $database->destination?->server;
        $filenames = $backup->executions()
            ->whereNotNull('filename')
            ->where('filename', '!=', '')
            ->pluck('filename')
            ->filter()
            ->all();

        if (! empty($filenames)) {
            if ($request->boolean('delete_associated_backups_locally') && $server) {
                deleteBackupsLocally($filenames, $server);
            }
            if ($request->boolean('delete_associated_backups_s3') && $backup->s3) {
                deleteBackupsS3($filenames, $backup->s3);
            }
        }

        $backup->delete();

        return redirect()->route('project.database.backup.index', compact('project_uuid', 'environment_uuid', 'database_uuid'))
            ->with('success', 'Scheduled backup deleted.');
    }

    public function backupNow(string $project_uuid, string $environment_uuid, string $database_uuid, string $backup_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof Model) {
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
        if (! $database instanceof Model) {
            return $database;
        }

        $backup = $this->resolveBackup($database, $backup_uuid, redirectOnMissing: true);
        if (! $backup instanceof ScheduledDatabaseBackup) {
            return $backup;
        }

        $backup->executions()->where('status', 'failed')->delete();

        return back()->with('success', 'Failed backups cleaned up.');
    }

    public function cleanupDeletedExecutions(string $project_uuid, string $environment_uuid, string $database_uuid, string $backup_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof Model) {
            return $database;
        }

        $backup = $this->resolveBackup($database, $backup_uuid, redirectOnMissing: true);
        if (! $backup instanceof ScheduledDatabaseBackup) {
            return $backup;
        }

        $deletedCount = $backup->executions()->where('local_storage_deleted', true)->count();
        if ($deletedCount === 0) {
            return back()->with('info', 'No backup entries found that are deleted from local storage.');
        }

        $backup->executions()->where('local_storage_deleted', true)->delete();

        return back()->with('success', "Cleaned up {$deletedCount} backup entries deleted from local storage.");
    }

    public function destroyExecution(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid, string $backup_uuid, int $execution_id): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof Model) {
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

        $execution = $backup->executions()->where('id', $execution_id)->first();
        if (! $execution) {
            return back()->with('error', 'Backup execution not found.');
        }

        $server = $database->destination?->server;

        try {
            if ($execution->filename) {
                deleteBackupsLocally($execution->filename, $server);
                if ($request->boolean('delete_backup_s3') && $backup->s3) {
                    deleteBackupsS3($execution->filename, $backup->s3);
                }
            }

            $execution->delete();

            return back()->with('success', 'Backup deleted.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to delete backup: '.$e->getMessage());
        }
    }

    public function start(string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof Model) {
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
        if (! $database instanceof Model) {
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
        if (! $database instanceof Model) {
            return $database;
        }

        $this->authorize('manage', $database);

        StopDatabase::dispatch($database, false, $request->boolean('docker_cleanup', true));

        return back()->with('info', 'Gracefully stopping database.');
    }

    public function checkStatus(string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof Model) {
            return $database;
        }

        if (! $database->destination->server->isFunctional()) {
            return back()->with('error', 'Server is not functional.');
        }

        GetContainersStatus::dispatch($database->destination->server);

        return back();
    }

    /**
     * @return (Model&StandaloneDatabaseInstance)|RedirectResponse
     */
    private function resolveDatabase(string $project_uuid, string $environment_uuid, string $database_uuid, bool $redirectOnMissing = false): Model|RedirectResponse
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

    private function resolveBackup(Model $database, string $backup_uuid, bool $redirectOnMissing = false): ScheduledDatabaseBackup|RedirectResponse
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
    private function headingProps(Model $database, array $parameters): array
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
    private function configurationCheckerProps(Model $database): array
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

    /**
     * @return array<string, mixed>
     */
    private function backupEditProps(ScheduledDatabaseBackup $backup): array
    {
        return [
            'id' => $backup->id,
            'databaseName' => $backup->database?->name,
            'databaseType' => $backup->database_type,
            'databaseId' => $backup->database_id,
            'status' => $backup->database?->status,
            'enabled' => (bool) $backup->enabled,
            'frequency' => $backup->frequency,
            'timezone' => data_get($backup->server(), 'settings.server_timezone', 'Instance timezone'),
            'timeout' => $backup->timeout,
            'saveS3' => (bool) $backup->save_s3,
            'disableLocalBackup' => (bool) $backup->disable_local_backup,
            's3StorageId' => $backup->s3_storage_id,
            'databasesToBackup' => $backup->databases_to_backup,
            'dumpAll' => (bool) $backup->dump_all,
            'databaseBackupRetentionAmountLocally' => $backup->database_backup_retention_amount_locally,
            'databaseBackupRetentionDaysLocally' => $backup->database_backup_retention_days_locally,
            'databaseBackupRetentionMaxStorageLocally' => (float) $backup->database_backup_retention_max_storage_locally,
            'databaseBackupRetentionAmountS3' => $backup->database_backup_retention_amount_s3,
            'databaseBackupRetentionDaysS3' => $backup->database_backup_retention_days_s3,
            'databaseBackupRetentionMaxStorageS3' => (float) $backup->database_backup_retention_max_storage_s3,
        ];
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function executionProps(ScheduledDatabaseBackupExecution $execution, array $parameters, string $backup_uuid): array
    {
        $server = $execution->scheduledDatabaseBackup?->server();
        $timingText = null;
        if ($execution->status === 'running') {
            $timingText = 'Running for '.calculateDuration($execution->created_at, now());
        } elseif ($execution->finished_at) {
            $timingText = Carbon::parse($execution->finished_at)->diffForHumans()
                .' ('.calculateDuration($execution->created_at, $execution->finished_at).')'
                .' • '.Carbon::parse($execution->finished_at)->format('M j, H:i');
        }

        return [
            'id' => $execution->id,
            'status' => $execution->status,
            's3Uploaded' => $execution->s3_uploaded,
            'timingText' => $timingText,
            'startedAt' => $server ? formatDateInServerTimezone($execution->created_at, $server) : null,
            'finishedAt' => ($execution->finished_at && $server) ? formatDateInServerTimezone($execution->finished_at, $server) : null,
            'databaseName' => $execution->database_name,
            'size' => $execution->size ? formatBytes($execution->size) : null,
            'filename' => $execution->filename,
            'message' => $execution->message,
            'localStorageDeleted' => (bool) $execution->local_storage_deleted,
            's3StorageDeleted' => (bool) $execution->s3_storage_deleted,
            'destroyUrl' => route('project.database.backup.execution.destroy', [...$parameters, 'backup_uuid' => $backup_uuid, 'execution_id' => $execution->id]),
            'downloadUrl' => route('download.backup', ['executionId' => $execution->id]),
        ];
    }
}
