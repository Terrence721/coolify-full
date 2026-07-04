<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $status
 * @property string|null $message
 * @property int|null $size
 * @property string|null $filename
 * @property int $scheduled_database_backup_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $database_name
 * @property string|null $finished_at
 * @property bool $local_storage_deleted
 * @property bool $s3_storage_deleted
 * @property bool|null $s3_uploaded
 * @property-read mixed $image
 * @property-read mixed $sanitized_name
 * @property-read ScheduledDatabaseBackup|null $scheduledDatabaseBackup
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledDatabaseBackupExecution newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledDatabaseBackupExecution newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledDatabaseBackupExecution query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledDatabaseBackupExecution whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledDatabaseBackupExecution whereDatabaseName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledDatabaseBackupExecution whereFilename($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledDatabaseBackupExecution whereFinishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledDatabaseBackupExecution whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledDatabaseBackupExecution whereLocalStorageDeleted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledDatabaseBackupExecution whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledDatabaseBackupExecution whereS3StorageDeleted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledDatabaseBackupExecution whereS3Uploaded($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledDatabaseBackupExecution whereScheduledDatabaseBackupId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledDatabaseBackupExecution whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledDatabaseBackupExecution whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledDatabaseBackupExecution whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledDatabaseBackupExecution whereUuid($value)
 *
 * @mixin \Eloquent
 */
class ScheduledDatabaseBackupExecution extends BaseModel
{
    protected $fillable = [
        'uuid',
        'scheduled_database_backup_id',
        'status',
        'message',
        'size',
        'filename',
        'database_name',
        'finished_at',
        'local_storage_deleted',
        's3_storage_deleted',
        's3_uploaded',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            's3_uploaded' => 'boolean',
            'local_storage_deleted' => 'boolean',
            's3_storage_deleted' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ScheduledDatabaseBackup, $this>
     */
    public function scheduledDatabaseBackup(): BelongsTo
    {
        return $this->belongsTo(ScheduledDatabaseBackup::class);
    }
}
