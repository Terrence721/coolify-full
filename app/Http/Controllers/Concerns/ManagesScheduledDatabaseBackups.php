<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

trait ManagesScheduledDatabaseBackups
{
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
     * @return array<string, mixed>
     */
    private function executionProps(ScheduledDatabaseBackupExecution $execution, string $destroyUrl, string $downloadUrl): array
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
            'destroyUrl' => $destroyUrl,
            'downloadUrl' => $downloadUrl,
        ];
    }

    /**
     * Validates and applies a backup-schedule update. Returns an error message on
     * failure, or null on success (the backup has already been saved).
     */
    private function applyBackupScheduleUpdate(Request $request, ScheduledDatabaseBackup $backup, int $s3TeamId): ?string
    {
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
            return 'Invalid Cron / Human expression';
        }

        if (filled($validated['databases_to_backup'] ?? null)) {
            try {
                validateDatabasesBackupInput($validated['databases_to_backup']);
            } catch (\Throwable $e) {
                return $e->getMessage();
            }
        }

        $availableS3Ids = S3Storage::where('team_id', $s3TeamId)->pluck('id');
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

        return null;
    }

    /**
     * Deletes local/S3 backup files associated with a schedule (as requested), then
     * the schedule itself.
     */
    private function deleteBackupScheduleFiles(Request $request, ScheduledDatabaseBackup $backup, ?Server $server): void
    {
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
    }

    /**
     * Deletes a single backup execution's files (as requested) and its record.
     * Returns an error message on failure, or null on success.
     */
    private function deleteBackupExecution(Request $request, ScheduledDatabaseBackup $backup, int $executionId, ?Server $server): ?string
    {
        $execution = $backup->executions()->where('id', $executionId)->first();
        if (! $execution) {
            return 'Backup execution not found.';
        }

        try {
            if ($execution->filename) {
                deleteBackupsLocally($execution->filename, $server);
                if ($request->boolean('delete_backup_s3') && $backup->s3) {
                    deleteBackupsS3($execution->filename, $backup->s3);
                }
            }

            $execution->delete();

            return null;
        } catch (\Throwable $e) {
            return 'Failed to delete backup: '.$e->getMessage();
        }
    }

    private function cleanupFailedBackupExecutions(ScheduledDatabaseBackup $backup): void
    {
        $backup->executions()->where('status', 'failed')->delete();
    }

    /**
     * Returns the number of cleaned-up executions.
     */
    private function cleanupDeletedBackupExecutions(ScheduledDatabaseBackup $backup): int
    {
        $deletedCount = $backup->executions()->where('local_storage_deleted', true)->count();
        if ($deletedCount > 0) {
            $backup->executions()->where('local_storage_deleted', true)->delete();
        }

        return $deletedCount;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function s3StorageOptions(int $teamId): array
    {
        return S3Storage::where('team_id', $teamId)->get()
            ->map(fn (S3Storage $s3) => ['id' => $s3->id, 'name' => $s3->name])
            ->values()->all();
    }
}
