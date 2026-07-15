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
 * @property string $keydb_password
 * @property string|null $keydb_conf
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereConfigHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereCustomDockerRunOptions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereDestinationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereDestinationType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereEnableSsl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereEnvironmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereHealthCheckEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereHealthCheckInterval($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereHealthCheckRetries($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereHealthCheckStartPeriod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereHealthCheckTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereIsIncludeTimestamps($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereIsLogDrainEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereIsPublic($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereKeydbConf($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereKeydbPassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereLastOnlineAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereLastRestartAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereLastRestartType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereLimitsCpuShares($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereLimitsCpus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereLimitsCpuset($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereLimitsMemory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereLimitsMemoryReservation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereLimitsMemorySwap($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereLimitsMemorySwappiness($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb wherePortsMappings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb wherePublicPort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb wherePublicPortTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereRestartCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneKeydb withoutTrashed()
 *
 * @mixin \Eloquent
 */
class StandaloneKeydb extends BaseModel implements StandaloneDatabaseInstance
{
    use ClearsGlobalSearchCache, HasDatabaseHealthCheck, HasFactory, HasMetrics, HasSafeStringAttribute, HasStandaloneDatabaseCommon, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'keydb_password',
        'keydb_conf',
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

    protected $appends = ['internal_db_url', 'external_db_url', 'server_status'];

    protected $casts = [
        'health_check_enabled' => 'boolean',
        'health_check_interval' => 'integer',
        'health_check_timeout' => 'integer',
        'health_check_retries' => 'integer',
        'health_check_start_period' => 'integer',
        'keydb_password' => 'encrypted',
        'public_port_timeout' => 'integer',
        'restart_count' => 'integer',
        'last_restart_at' => 'datetime',
        'last_restart_type' => 'string',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'keydb-data-'.$database->uuid,
                'mount_path' => '/data',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
            ]);
        });
    }

    protected function configHashExtra(): string
    {
        return (string) $this->keydb_conf;
    }

    public function type(): string
    {
        return 'standalone-keydb';
    }

    protected function internalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                $scheme = $this->enable_ssl ? 'rediss' : 'redis';
                $port = $this->enable_ssl ? 6380 : 6379;
                $encodedPass = rawurlencode($this->keydb_password);
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
                    $serverIp = $this->destination->server->get_ip;
                    if (empty($serverIp)) {
                        return null;
                    }
                    $scheme = $this->enable_ssl ? 'rediss' : 'redis';
                    $encodedPass = rawurlencode($this->keydb_password);
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

    public function isBackupSolutionAvailable(): bool
    {
        return false;
    }
}
