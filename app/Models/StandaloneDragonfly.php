<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property string $dragonfly_password
 * @property bool $is_log_drain_enabled
 * @property bool $is_include_timestamps
 * @property Carbon|null $deleted_at
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereConfigHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereCustomDockerRunOptions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereDestinationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereDestinationType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereDragonflyPassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereEnableSsl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereEnvironmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereHealthCheckEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereHealthCheckInterval($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereHealthCheckRetries($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereHealthCheckStartPeriod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereHealthCheckTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereIsIncludeTimestamps($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereIsLogDrainEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereIsPublic($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereLastOnlineAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereLastRestartAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereLastRestartType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereLimitsCpuShares($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereLimitsCpus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereLimitsCpuset($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereLimitsMemory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereLimitsMemoryReservation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereLimitsMemorySwap($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereLimitsMemorySwappiness($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly wherePortsMappings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly wherePublicPort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly wherePublicPortTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereRestartCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDragonfly withoutTrashed()
 *
 * @mixin \Eloquent
 */
class StandaloneDragonfly extends BaseModel
{
    use ClearsGlobalSearchCache, HasDatabaseHealthCheck, HasFactory, HasMetrics, HasSafeStringAttribute, HasStandaloneDatabaseCommon, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'dragonfly_password',
        'is_log_drain_enabled',
        'is_include_timestamps',
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
        'dragonfly_password' => 'encrypted',
        'public_port_timeout' => 'integer',
        'restart_count' => 'integer',
        'last_restart_at' => 'datetime',
        'last_restart_type' => 'string',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'dragonfly-data-'.$database->uuid,
                'mount_path' => '/data',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
            ]);
        });
    }

    public function type(): string
    {
        return 'standalone-dragonfly';
    }

    protected function internalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                $scheme = $this->enable_ssl ? 'rediss' : 'redis';
                $port = $this->enable_ssl ? 6380 : 6379;
                $encodedPass = rawurlencode($this->dragonfly_password);
                $url = "{$scheme}://:{$encodedPass}@{$this->uuid}:{$port}/0";

                if ($this->enable_ssl && $this->ssl_mode === 'verify-ca') {
                    $url .= '?cacert=/etc/ssl/certs/coolify-ca.crt';
                }

                return $url;
            }
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
                    $scheme = $this->enable_ssl ? 'rediss' : 'redis';
                    $encodedPass = rawurlencode($this->dragonfly_password);
                    $url = "{$scheme}://:{$encodedPass}@{$serverIp}:{$this->public_port}/0";

                    if ($this->enable_ssl && $this->ssl_mode === 'verify-ca') {
                        $url .= '?cacert=/etc/ssl/certs/coolify-ca.crt';
                    }

                    return $url;
                }

                return null;
            }
        );
    }

    public function isBackupSolutionAvailable()
    {
        return false;
    }
}
