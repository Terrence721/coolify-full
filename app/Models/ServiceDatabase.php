<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ServiceDatabaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Stringable;

/**
 * @property-read Service $service
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $human_name
 * @property string|null $description
 * @property string|null $ports
 * @property string|null $exposes
 * @property string $status
 * @property int $service_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property bool $exclude_from_status
 * @property string|null $image
 * @property int|null $public_port
 * @property bool $is_public
 * @property bool $is_log_drain_enabled
 * @property bool $is_include_timestamps
 * @property Carbon|null $deleted_at
 * @property bool $is_gzip_enabled
 * @property bool $is_stripprefix_enabled
 * @property string $last_online_at
 * @property bool $is_migrated
 * @property string|null $custom_type
 * @property int|null $public_port_timeout
 * @property-read Collection<int, LocalFileVolume> $fileStorages
 * @property-read int|null $file_storages_count
 * @property-read Collection<int, LocalPersistentVolume> $persistentStorages
 * @property-read int|null $persistent_storages_count
 * @property-read mixed $sanitized_name
 * @property-read Collection<int, ScheduledDatabaseBackup> $scheduledBackups
 * @property-read int|null $scheduled_backups_count
 *
 * @method static Builder<static>|ServiceDatabase newModelQuery()
 * @method static Builder<static>|ServiceDatabase newQuery()
 * @method static Builder<static>|ServiceDatabase onlyTrashed()
 * @method static Builder<static>|ServiceDatabase query()
 * @method static Builder<static>|ServiceDatabase whereCreatedAt($value)
 * @method static Builder<static>|ServiceDatabase whereCustomType($value)
 * @method static Builder<static>|ServiceDatabase whereDeletedAt($value)
 * @method static Builder<static>|ServiceDatabase whereDescription($value)
 * @method static Builder<static>|ServiceDatabase whereExcludeFromStatus($value)
 * @method static Builder<static>|ServiceDatabase whereExposes($value)
 * @method static Builder<static>|ServiceDatabase whereHumanName($value)
 * @method static Builder<static>|ServiceDatabase whereId($value)
 * @method static Builder<static>|ServiceDatabase whereImage($value)
 * @method static Builder<static>|ServiceDatabase whereIsGzipEnabled($value)
 * @method static Builder<static>|ServiceDatabase whereIsIncludeTimestamps($value)
 * @method static Builder<static>|ServiceDatabase whereIsLogDrainEnabled($value)
 * @method static Builder<static>|ServiceDatabase whereIsMigrated($value)
 * @method static Builder<static>|ServiceDatabase whereIsPublic($value)
 * @method static Builder<static>|ServiceDatabase whereIsStripprefixEnabled($value)
 * @method static Builder<static>|ServiceDatabase whereLastOnlineAt($value)
 * @method static Builder<static>|ServiceDatabase whereName($value)
 * @method static Builder<static>|ServiceDatabase wherePorts($value)
 * @method static Builder<static>|ServiceDatabase wherePublicPort($value)
 * @method static Builder<static>|ServiceDatabase wherePublicPortTimeout($value)
 * @method static Builder<static>|ServiceDatabase whereServiceId($value)
 * @method static Builder<static>|ServiceDatabase whereStatus($value)
 * @method static Builder<static>|ServiceDatabase whereUpdatedAt($value)
 * @method static Builder<static>|ServiceDatabase whereUuid($value)
 * @method static Builder<static>|ServiceDatabase withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|ServiceDatabase withoutTrashed()
 *
 * @mixin \Eloquent
 */
class ServiceDatabase extends BaseModel
{
    /** @use HasFactory<ServiceDatabaseFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'service_id',
        'name',
        'human_name',
        'description',
        'fqdn',
        'ports',
        'exposes',
        'status',
        'exclude_from_status',
        'image',
        'public_port',
        'is_public',
        'is_log_drain_enabled',
        'is_include_timestamps',
        'is_gzip_enabled',
        'is_stripprefix_enabled',
        'last_online_at',
        'is_migrated',
        'custom_type',
        'public_port_timeout',
    ];

    /**
     * is_gzip_enabled/is_stripprefix_enabled/is_log_drain_enabled feed their respective
     * isGzipEnabled()/isStripprefixEnabled()/isLogDrainEnabled(): bool accessors — without
     * the casts, SQLite (the test database) returns raw ints that fatal under
     * declare(strict_types=1); PostgreSQL happened to return real booleans, masking it.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'public_port_timeout' => 'integer',
            'is_gzip_enabled' => 'boolean',
            'is_stripprefix_enabled' => 'boolean',
            'is_log_drain_enabled' => 'boolean',
        ];
    }

    protected static function booted()
    {
        static::deleting(function ($service) {
            $service->persistentStorages()->delete();
            $service->fileStorages()->delete();
            $service->scheduledBackups()->delete();
        });
        static::saving(function ($service) {
            if ($service->isDirty('status')) {
                $service->last_online_at = now();
            }
        });
    }

    /**
     * @return Builder<self>
     */
    public static function ownedByCurrentTeamAPI(int|string $teamId): Builder
    {
        return ServiceDatabase::whereRelation('service.environment.project.team', 'id', $teamId)->orderBy('name');
    }

    /**
     * Get query builder for service databases owned by current team.
     * If you need all service databases without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    /**
     * @return Builder<self>
     */
    public static function ownedByCurrentTeam(): Builder
    {
        $team = currentTeam();

        if (! $team) {
            return ServiceDatabase::query()->whereRaw('1 = 0')->orderBy('name');
        }

        return ServiceDatabase::whereRelation('service.environment.project.team', 'id', $team->id)->orderBy('name');
    }

    /**
     * Get all service databases owned by current team (cached for request duration).
     *
     * @return Collection<int, ServiceDatabase>
     */
    public static function ownedByCurrentTeamCached(): Collection
    {
        return once(function () {
            return ServiceDatabase::ownedByCurrentTeam()->get();
        });
    }

    public function restart(): void
    {
        $container_id = $this->name.'-'.$this->service->uuid;
        remote_process(["docker restart {$container_id}"], $this->service->server);
    }

    public function isRunning(): bool
    {
        return str($this->status)->contains('running');
    }

    public function isExited(): bool
    {
        return str($this->status)->contains('exited');
    }

    public function isLogDrainEnabled(): bool
    {
        return data_get($this, 'is_log_drain_enabled', false);
    }

    public function isStripprefixEnabled(): bool
    {
        return data_get($this, 'is_stripprefix_enabled', true);
    }

    public function isGzipEnabled(): bool
    {
        return data_get($this, 'is_gzip_enabled', true);
    }

    public function type(): string
    {
        return 'service';
    }

    public function serviceType(): ?Stringable
    {
        return null;
    }

    public function databaseType(): string
    {
        if (filled($this->custom_type)) {
            return 'standalone-'.$this->custom_type;
        }
        $image = str($this->image)->before(':');
        if ($image->contains('supabase/postgres')) {
            $finalImage = 'supabase/postgres';
        } elseif ($image->contains('timescale')) {
            $finalImage = 'postgresql';
        } elseif ($image->contains('pgvector')) {
            $finalImage = 'postgresql';
        } elseif ($image->contains('postgres') || $image->contains('postgis')) {
            $finalImage = 'postgresql';
        } else {
            $finalImage = $image;
        }

        return "standalone-$finalImage";
    }

    public function getServiceDatabaseUrl(): string
    {
        $port = $this->public_port;
        $realIp = $this->service->server->ip;
        if ($this->service->server->isLocalhost() || isDev()) {
            $realIp = base_ip();
        }

        return "{$realIp}:{$port}";
    }

    public function team(): mixed
    {
        return data_get($this, 'service.environment.project.team');
    }

    public function workdir(): string
    {
        return service_configuration_dir()."/{$this->service->uuid}";
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return MorphMany<LocalPersistentVolume, $this>
     */
    public function persistentStorages(): MorphMany
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }

    /**
     * @return MorphMany<LocalFileVolume, $this>
     */
    public function fileStorages(): MorphMany
    {
        return $this->morphMany(LocalFileVolume::class, 'resource');
    }

    public function getFilesFromServer(bool $isInit = false): void
    {
        getFilesystemVolumesFromServer($this, $isInit);
    }

    /**
     * @return MorphMany<ScheduledDatabaseBackup, $this>
     */
    public function scheduledBackups(): MorphMany
    {
        return $this->morphMany(ScheduledDatabaseBackup::class, 'database');
    }

    public function isBackupSolutionAvailable(): bool
    {
        return str($this->databaseType())->contains('mysql') ||
            str($this->databaseType())->contains('postgres') ||
            str($this->databaseType())->contains('postgis') ||
            str($this->databaseType())->contains('mariadb') ||
            str($this->databaseType())->contains('mongo') ||
            filled($this->custom_type);
    }
}
