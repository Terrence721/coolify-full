<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasDatabaseHealthCheck;
use App\Traits\HasMetrics;
use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property-read string $internal_db_url
 * @property-read string|null $external_db_url
 * @property-read StandaloneDocker|SwarmDocker|null $destination
 * @property-read Server|mixed|null $server
 * @property-read string $database_type
 * @property-read Collection<int, EnvironmentVariable> $runtime_environment_variables
 * @property-read Collection<int, LocalPersistentVolume> $persistentStorages
 * @property-read array<int, string> $ports_mappings_array
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property string $postgres_user
 * @property string $postgres_password
 * @property string $postgres_db
 * @property string|null $postgres_initdb_args
 * @property string|null $postgres_host_auth_method
 * @property array<array-key, mixed>|null $init_scripts
 * @property string $status
 * @property string $image
 * @property bool $is_public
 * @property int|null $public_port
 * @property string|null $ports_mappings
 * @property string $limits_memory
 * @property string $limits_memory_swap
 * @property int $limits_memory_swappiness
 * @property string $limits_memory_reservation
 * @property string $limits_cpus
 * @property string|null $limits_cpuset
 * @property int $limits_cpu_shares
 * @property string|null $started_at
 * @property string $destination_type
 * @property int $destination_id
 * @property int|null $environment_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $postgres_conf
 * @property bool $is_log_drain_enabled
 * @property bool $is_include_timestamps
 * @property Carbon|null $deleted_at
 * @property string|null $config_hash
 * @property string|null $custom_docker_run_options
 * @property string $last_online_at
 * @property bool $enable_ssl
 * @property string $ssl_mode
 * @property int $restart_count
 * @property Carbon|null $last_restart_at
 * @property string|null $last_restart_type
 * @property int|null $public_port_timeout
 * @property bool $health_check_enabled
 * @property int $health_check_interval
 * @property int $health_check_timeout
 * @property int $health_check_retries
 * @property int $health_check_start_period
 * @property-read Environment|null $environment
 * @property-read Collection<int, EnvironmentVariable> $environment_variables
 * @property-read int|null $environment_variables_count
 * @property-read Collection<int, LocalFileVolume> $fileStorages
 * @property-read int|null $file_storages_count
 * @property-read int|null $persistent_storages_count
 * @property-read int|null $runtime_environment_variables_count
 * @property-read mixed $sanitized_name
 * @property-read Collection<int, ScheduledDatabaseBackup> $scheduledBackups
 * @property-read int|null $scheduled_backups_count
 * @property-read mixed $server_status
 * @property-read Collection<int, SslCertificate> $sslCertificates
 * @property-read int|null $ssl_certificates_count
 * @property-read Collection<int, Tag> $tags
 * @property-read int|null $tags_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereConfigHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereCustomDockerRunOptions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereDestinationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereDestinationType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereEnableSsl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereEnvironmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereHealthCheckEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereHealthCheckInterval($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereHealthCheckRetries($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereHealthCheckStartPeriod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereHealthCheckTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereInitScripts($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereIsIncludeTimestamps($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereIsLogDrainEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereIsPublic($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereLastOnlineAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereLastRestartAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereLastRestartType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereLimitsCpuShares($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereLimitsCpus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereLimitsCpuset($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereLimitsMemory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereLimitsMemoryReservation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereLimitsMemorySwap($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereLimitsMemorySwappiness($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql wherePortsMappings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql wherePostgresConf($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql wherePostgresDb($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql wherePostgresHostAuthMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql wherePostgresInitdbArgs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql wherePostgresPassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql wherePostgresUser($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql wherePublicPort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql wherePublicPortTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereRestartCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereSslMode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandalonePostgresql withoutTrashed()
 *
 * @mixin \Eloquent
 */
class StandalonePostgresql extends BaseModel
{
    use ClearsGlobalSearchCache, HasDatabaseHealthCheck, HasFactory, HasMetrics, HasSafeStringAttribute, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'postgres_user',
        'postgres_password',
        'postgres_db',
        'postgres_initdb_args',
        'postgres_host_auth_method',
        'postgres_conf',
        'init_scripts',
        'status',
        'image',
        'is_public',
        'public_port',
        'ports_mappings',
        'limits_memory',
        'limits_memory_swap',
        'limits_memory_swappiness',
        'limits_memory_reservation',
        'limits_cpus',
        'limits_cpuset',
        'limits_cpu_shares',
        'started_at',
        'restart_count',
        'last_restart_at',
        'last_restart_type',
        'last_online_at',
        'public_port_timeout',
        'enable_ssl',
        'ssl_mode',
        'is_log_drain_enabled',
        'is_include_timestamps',
        'custom_docker_run_options',
        'destination_type',
        'destination_id',
        'environment_id',
        'health_check_enabled',
        'health_check_interval',
        'health_check_timeout',
        'health_check_retries',
        'health_check_start_period',
    ];

    protected $appends = ['internal_db_url', 'external_db_url', 'database_type', 'server_status'];

    protected $casts = [
        'health_check_enabled' => 'boolean',
        'health_check_interval' => 'integer',
        'health_check_timeout' => 'integer',
        'health_check_retries' => 'integer',
        'health_check_start_period' => 'integer',
        'init_scripts' => 'array',
        'postgres_password' => 'encrypted',
        'public_port_timeout' => 'integer',
        'restart_count' => 'integer',
        'last_restart_at' => 'datetime',
        'last_restart_type' => 'string',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            // This is really stupid and it took me 1h to figure out why the image was not loading properly. This is exactly the reason why we need to use the action pattern because Model events and Accessors are a fragile mess!
            $image = (string) ($database->getAttributes()['image'] ?? '');
            $majorVersion = 0;

            if (preg_match('/:(?:pg)?(\d+)/i', $image, $matches)) {
                $majorVersion = (int) $matches[1];
            }

            // PostgreSQL 18+ uses /var/lib/postgresql as mount path
            // Older versions use /var/lib/postgresql/data
            $mountPath = $majorVersion >= 18
                ? '/var/lib/postgresql'
                : '/var/lib/postgresql/data';

            LocalPersistentVolume::create([
                'name' => 'postgres-data-'.$database->uuid,
                'mount_path' => $mountPath,
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
            ]);
        });
        static::forceDeleting(function ($database) {
            $database->persistentStorages()->delete();
            $database->scheduledBackups()->delete();
            $database->environment_variables()->delete();
            $database->tags()->detach();
        });
        static::saving(function ($database) {
            if ($database->isDirty('status')) {
                $database->last_online_at = now();
            }
        });
    }

    /**
     * Get query builder for PostgreSQL databases owned by current team.
     * If you need all databases without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    public static function ownedByCurrentTeam()
    {
        $team = currentTeam();

        if (! $team) {
            return StandalonePostgresql::query()->whereRaw('1 = 0')->orderBy('name');
        }

        return StandalonePostgresql::whereRelation('environment.project.team', 'id', $team->id)->orderBy('name');
    }

    /**
     * Get all PostgreSQL databases owned by current team (cached for request duration).
     */
    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return StandalonePostgresql::ownedByCurrentTeam()->get();
        });
    }

    public function workdir()
    {
        return database_configuration_dir()."/{$this->uuid}";
    }

    protected function serverStatus(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->destination->server->isFunctional();
            }
        );
    }

    public function deleteConfigurations()
    {
        $server = data_get($this, 'destination.server');
        $workdir = $this->workdir();
        if (str($workdir)->endsWith($this->uuid)) {
            instant_remote_process(['rm -rf '.$this->workdir()], $server, false);
        }
    }

    public function deleteVolumes()
    {
        $persistentStorages = $this->persistentStorages()->get() ?? collect();
        if ($persistentStorages->count() === 0) {
            return;
        }
        $server = data_get($this, 'destination.server');
        foreach ($persistentStorages as $storage) {
            instant_remote_process(['docker volume rm -f '.escapeshellarg($storage->name)], $server, false);
        }
    }

    public function isConfigurationChanged(bool $save = false)
    {
        $newConfigHash = $this->image.$this->ports_mappings.$this->postgres_initdb_args.$this->postgres_host_auth_method;
        $newConfigHash .= $this->healthCheckConfigurationHash();
        $newConfigHash .= json_encode($this->environment_variables()->get('value')->sort());
        $newConfigHash = md5($newConfigHash);
        $oldConfigHash = data_get($this, 'config_hash');
        if ($oldConfigHash === null) {
            if ($save) {
                $this->config_hash = $newConfigHash;
                $this->save();
            }

            return true;
        }
        if ($oldConfigHash === $newConfigHash) {
            return false;
        } else {
            if ($save) {
                $this->config_hash = $newConfigHash;
                $this->save();
            }

            return true;
        }
    }

    public function isRunning()
    {
        return (bool) str($this->status)->contains('running');
    }

    public function isExited()
    {
        return (bool) str($this->status)->startsWith('exited');
    }

    public function realStatus()
    {
        return $this->getRawOriginal('status');
    }

    public function status(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (str($value)->contains('(')) {
                    $status = str($value)->before('(')->trim()->value();
                    $health = str($value)->after('(')->before(')')->trim()->value() ?? 'unhealthy';
                } elseif (str($value)->contains(':')) {
                    $status = str($value)->before(':')->trim()->value();
                    $health = str($value)->after(':')->trim()->value() ?? 'unhealthy';
                } else {
                    $status = $value;
                    $health = 'unhealthy';
                }

                return "$status:$health";
            },
            get: function ($value) {
                if (str($value)->contains('(')) {
                    $status = str($value)->before('(')->trim()->value();
                    $health = str($value)->after('(')->before(')')->trim()->value() ?? 'unhealthy';
                } elseif (str($value)->contains(':')) {
                    $status = str($value)->before(':')->trim()->value();
                    $health = str($value)->after(':')->trim()->value() ?? 'unhealthy';
                } else {
                    $status = $value;
                    $health = 'unhealthy';
                }

                return "$status:$health";
            },
        );
    }

    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function project(): mixed
    {
        return data_get($this, 'environment.project');
    }

    public function link()
    {
        if (data_get($this, 'environment.project.uuid')) {
            return route('project.database.configuration', [
                'project_uuid' => data_get($this, 'environment.project.uuid'),
                'environment_uuid' => data_get($this, 'environment.uuid'),
                'database_uuid' => data_get($this, 'uuid'),
            ]);
        }

        return null;
    }

    public function isLogDrainEnabled()
    {
        return data_get($this, 'is_log_drain_enabled', false);
    }

    public function portsMappings(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === '' ? null : $value,
        );
    }

    public function portsMappingsArray(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->ports_mappings)
                ? []
                : explode(',', $this->ports_mappings),

        );
    }

    public function team()
    {
        return data_get($this, 'environment.project.team');
    }

    public function databaseType(): Attribute
    {
        return new Attribute(
            get: fn () => $this->type(),
        );
    }

    public function type(): string
    {
        return 'standalone-postgresql';
    }

    protected function internalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                $encodedUser = rawurlencode($this->postgres_user);
                $encodedPass = rawurlencode($this->postgres_password);
                $url = "postgres://{$encodedUser}:{$encodedPass}@{$this->uuid}:5432/{$this->postgres_db}";
                if ($this->enable_ssl) {
                    $url .= "?sslmode={$this->ssl_mode}";
                    if (in_array($this->ssl_mode, ['verify-ca', 'verify-full'])) {
                        $url .= '&sslrootcert=/etc/ssl/certs/coolify-ca.crt';
                    }
                }

                return $url;
            },
        );
    }

    protected function externalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                if ($this->is_public && $this->public_port) {
                    $serverIp = $this->destination->server->getIp;
                    if (empty($serverIp)) {
                        return null;
                    }
                    $encodedUser = rawurlencode($this->postgres_user);
                    $encodedPass = rawurlencode($this->postgres_password);
                    $url = "postgres://{$encodedUser}:{$encodedPass}@{$serverIp}:{$this->public_port}/{$this->postgres_db}";
                    if ($this->enable_ssl) {
                        $url .= "?sslmode={$this->ssl_mode}";
                        if (in_array($this->ssl_mode, ['verify-ca', 'verify-full'])) {
                            $url .= '&sslrootcert=/etc/ssl/certs/coolify-ca.crt';
                        }
                    }

                    return $url;
                }

                return null;
            }
        );
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }

    public function fileStorages()
    {
        return $this->morphMany(LocalFileVolume::class, 'resource');
    }

    public function sslCertificates()
    {
        return $this->morphMany(SslCertificate::class, 'resource');
    }

    public function destination()
    {
        return $this->morphTo();
    }

    public function runtime_environment_variables()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable');
    }

    public function scheduledBackups()
    {
        return $this->morphMany(ScheduledDatabaseBackup::class, 'database');
    }

    public function environment_variables()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable');
    }

    public function isBackupSolutionAvailable()
    {
        return true;
    }
}
