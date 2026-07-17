<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\StandaloneDatabaseInstance;
use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasDatabaseHealthCheck;
use App\Traits\HasMetrics;
use App\Traits\HasSafeStringAttribute;
use App\Traits\HasStandaloneDatabaseCommon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property-read string $internal_db_url
 * @property-read string|null $external_db_url
 * @property string|null $redis_username
 * @property string|null $redis_password
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
 * @property string|null $redis_conf
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
 * @property bool $is_log_drain_enabled
 * @property bool $is_include_timestamps
 * @property Carbon|null $deleted_at
 * @property string|null $config_hash
 * @property string|null $custom_docker_run_options
 * @property string $last_online_at
 * @property bool $enable_ssl
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereConfigHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereCustomDockerRunOptions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereDestinationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereDestinationType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereEnableSsl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereEnvironmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereHealthCheckEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereHealthCheckInterval($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereHealthCheckRetries($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereHealthCheckStartPeriod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereHealthCheckTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereIsIncludeTimestamps($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereIsLogDrainEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereIsPublic($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereLastOnlineAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereLastRestartAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereLastRestartType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereLimitsCpuShares($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereLimitsCpus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereLimitsCpuset($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereLimitsMemory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereLimitsMemoryReservation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereLimitsMemorySwap($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereLimitsMemorySwappiness($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis wherePortsMappings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis wherePublicPort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis wherePublicPortTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereRedisConf($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereRestartCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneRedis withoutTrashed()
 *
 * @mixin \Eloquent
 */
class StandaloneRedis extends BaseModel implements StandaloneDatabaseInstance
{
    use ClearsGlobalSearchCache, HasDatabaseHealthCheck, HasFactory, HasMetrics, HasSafeStringAttribute, HasStandaloneDatabaseCommon, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'redis_conf',
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
        'public_port_timeout' => 'integer',
        'restart_count' => 'integer',
        'last_restart_at' => 'datetime',
        'last_restart_type' => 'string',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'redis-data-'.$database->uuid,
                'mount_path' => '/data',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
            ]);
        });
        static::retrieved(function ($database) {
            if (! $database->redis_username) {
                $database->redis_username = 'default';
            }
        });
    }

    protected function configHashExtra(): string
    {
        return (string) $this->redis_conf;
    }

    public function type(): string
    {
        return 'standalone-redis';
    }

    /**
     * @return Attribute<string, never>
     */
    protected function internalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                $redis_version = $this->getRedisVersion();
                $username_part = version_compare($redis_version, '6.0', '>=') ? rawurlencode($this->redis_username).':' : '';
                // redis_password reads from a runtime_environment_variables row that only
                // exists after the first StartRedis deploy — a freshly created, never-started
                // database has none yet, so this must tolerate null rather than fatal.
                $encodedPass = rawurlencode((string) $this->redis_password);
                $scheme = $this->enable_ssl ? 'rediss' : 'redis';
                $port = $this->enable_ssl ? 6380 : 6379;
                $url = "{$scheme}://{$username_part}{$encodedPass}@{$this->uuid}:{$port}/0";

                return $url;
            }
        );
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function externalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                if ($this->is_public && $this->public_port) {
                    $serverIp = $this->destination->server->get_ip;
                    if (empty($serverIp)) {
                        return null;
                    }
                    $redis_version = $this->getRedisVersion();
                    $username_part = version_compare($redis_version, '6.0', '>=') ? rawurlencode($this->redis_username).':' : '';
                    $encodedPass = rawurlencode((string) $this->redis_password);
                    $scheme = $this->enable_ssl ? 'rediss' : 'redis';
                    $url = "{$scheme}://{$username_part}{$encodedPass}@{$serverIp}:{$this->public_port}/0";

                    return $url;
                }

                return null;
            }
        );
    }

    public function getRedisVersion(): string
    {
        $image_parts = explode(':', $this->image);

        return $image_parts[1] ?? '0.0';
    }

    public function isBackupSolutionAvailable(): bool
    {
        return false;
    }

    /**
     * @return Attribute<string|null, never>
     */
    public function redisPassword(): Attribute
    {
        return new Attribute(
            get: function () {
                $password = $this->runtime_environment_variables()->where('key', 'REDIS_PASSWORD')->first();
                if (! $password) {
                    return null;
                }

                return $password->value;
            },

        );
    }

    /**
     * @return Attribute<string, never>
     */
    public function redisUsername(): Attribute
    {
        return new Attribute(
            get: function () {
                $username = $this->runtime_environment_variables()->where('key', 'REDIS_USERNAME')->first();
                if (! $username) {
                    $this->runtime_environment_variables()->create([
                        'key' => 'REDIS_USERNAME',
                        'value' => 'default',
                    ]);

                    return 'default';
                }

                return $username->value;
            }
        );
    }
}
