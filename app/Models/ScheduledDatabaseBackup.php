<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property-read S3Storage|null $s3
 * @property int $id
 * @property string|null $description
 * @property string $uuid
 * @property bool $enabled
 * @property bool $save_s3
 * @property string $frequency
 * @property int $database_backup_retention_amount_locally
 * @property string $database_type
 * @property int $database_id
 * @property int|null $s3_storage_id
 * @property int $team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $databases_to_backup
 * @property bool $dump_all
 * @property int $database_backup_retention_days_locally
 * @property float $database_backup_retention_max_storage_locally
 * @property int $database_backup_retention_amount_s3
 * @property int $database_backup_retention_days_s3
 * @property float $database_backup_retention_max_storage_s3
 * @property int $timeout
 * @property bool $disable_local_backup
 * @property-read Model|\Eloquent $database
 * @property-read Collection<int, ScheduledDatabaseBackupExecution> $executions
 * @property-read int|null $executions_count
 * @property-read mixed $image
 * @property-read ScheduledDatabaseBackupExecution|null $latest_log
 * @property-read mixed $sanitized_name
 * @property-read Team|null $team
 *
 * @method static Builder<static>|ScheduledDatabaseBackup newModelQuery()
 * @method static Builder<static>|ScheduledDatabaseBackup newQuery()
 * @method static Builder<static>|ScheduledDatabaseBackup query()
 * @method static Builder<static>|ScheduledDatabaseBackup whereCreatedAt($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereDatabaseBackupRetentionAmountLocally($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereDatabaseBackupRetentionAmountS3($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereDatabaseBackupRetentionDaysLocally($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereDatabaseBackupRetentionDaysS3($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereDatabaseBackupRetentionMaxStorageLocally($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereDatabaseBackupRetentionMaxStorageS3($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereDatabaseId($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereDatabaseType($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereDatabasesToBackup($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereDescription($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereDisableLocalBackup($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereDumpAll($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereEnabled($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereFrequency($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereId($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereS3StorageId($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereSaveS3($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereTeamId($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereTimeout($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereUpdatedAt($value)
 * @method static Builder<static>|ScheduledDatabaseBackup whereUuid($value)
 *
 * @mixin \Eloquent
 */
class ScheduledDatabaseBackup extends BaseModel
{
    protected function casts(): array
    {
        return [
            'database_backup_retention_max_storage_locally' => 'float',
            'database_backup_retention_max_storage_s3' => 'float',
        ];
    }

    protected $fillable = [
        'uuid',
        'team_id',
        'description',
        'enabled',
        'save_s3',
        'frequency',
        'database_backup_retention_amount_locally',
        'database_type',
        'database_id',
        's3_storage_id',
        'databases_to_backup',
        'dump_all',
        'database_backup_retention_days_locally',
        'database_backup_retention_max_storage_locally',
        'database_backup_retention_amount_s3',
        'database_backup_retention_days_s3',
        'database_backup_retention_max_storage_s3',
        'timeout',
        'disable_local_backup',
    ];

    /**
     * @return Builder<self>
     */
    public static function ownedByCurrentTeam(): Builder
    {
        return ScheduledDatabaseBackup::whereRelation('team', 'id', currentTeam()->id)->orderBy('created_at', 'desc');
    }

    /**
     * @return Builder<self>
     */
    public static function ownedByCurrentTeamAPI(int $teamId): Builder
    {
        return ScheduledDatabaseBackup::whereRelation('team', 'id', $teamId)->orderBy('created_at', 'desc');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function database(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasOne<ScheduledDatabaseBackupExecution, $this>
     */
    public function latest_log(): HasOne
    {
        return $this->hasOne(ScheduledDatabaseBackupExecution::class)->latest();
    }

    /**
     * @return HasMany<ScheduledDatabaseBackupExecution, $this>
     */
    public function executions(): HasMany
    {
        // Last execution first
        return $this->hasMany(ScheduledDatabaseBackupExecution::class)->orderBy('created_at', 'desc');
    }

    /**
     * @return BelongsTo<S3Storage, $this>
     */
    public function s3(): BelongsTo
    {
        return $this->belongsTo(S3Storage::class, 's3_storage_id');
    }

    public function get_last_days_backup_status($days = 7)
    {
        return $this->hasMany(ScheduledDatabaseBackupExecution::class)->where('created_at', '>=', now()->subDays($days))->get();
    }

    public function executionsPaginated(int $skip = 0, int $take = 10)
    {
        $executions = $this->hasMany(ScheduledDatabaseBackupExecution::class)->orderBy('created_at', 'desc');
        $count = $executions->count();
        $executions = $executions->skip($skip)->take($take)->get();

        return [
            'count' => $count,
            'executions' => $executions,
        ];
    }

    public function server(): ?Server
    {
        if ($this->database) {
            if ($this->database instanceof ServiceDatabase) {
                $destination = data_get($this->database->service, 'destination');
                $server = data_get($destination, 'server');
            } else {
                $destination = data_get($this->database, 'destination');
                $server = data_get($destination, 'server');
            }
            if ($server) {
                return $server;
            }
        }

        return null;
    }
}
