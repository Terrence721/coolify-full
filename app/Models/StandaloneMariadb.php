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
 * @property string $mariadb_root_password
 * @property string $mariadb_user
 * @property string $mariadb_password
 * @property string $mariadb_database
 * @property string|null $mariadb_conf
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereConfigHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereCustomDockerRunOptions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereDestinationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereDestinationType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereEnableSsl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereEnvironmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereHealthCheckEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereHealthCheckInterval($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereHealthCheckRetries($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereHealthCheckStartPeriod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereHealthCheckTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereIsLogDrainEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereIsPublic($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereLastOnlineAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereLastRestartAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereLastRestartType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereLimitsCpuShares($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereLimitsCpus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereLimitsCpuset($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereLimitsMemory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereLimitsMemoryReservation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereLimitsMemorySwap($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereLimitsMemorySwappiness($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereMariadbConf($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereMariadbDatabase($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereMariadbPassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereMariadbRootPassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereMariadbUser($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb wherePortsMappings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb wherePublicPort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb wherePublicPortTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereRestartCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMariadb withoutTrashed()
 *
 * @mixin \Eloquent
 */
class StandaloneMariadb extends BaseModel
{
    use ClearsGlobalSearchCache, HasDatabaseHealthCheck, HasFactory, HasMetrics, HasSafeStringAttribute, HasStandaloneDatabaseCommon, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'mariadb_root_password',
        'mariadb_user',
        'mariadb_password',
        'mariadb_database',
        'mariadb_conf',
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
        'mariadb_password' => 'encrypted',
        'public_port_timeout' => 'integer',
        'restart_count' => 'integer',
        'last_restart_at' => 'datetime',
        'last_restart_type' => 'string',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'mariadb-data-'.$database->uuid,
                'mount_path' => '/var/lib/mysql',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
            ]);
        });
    }

    protected function configHashExtra(): string
    {
        return (string) $this->mariadb_conf;
    }

    public function type(): string
    {
        return 'standalone-mariadb';
    }

    protected function internalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                $encodedUser = rawurlencode($this->mariadb_user);
                $encodedPass = rawurlencode($this->mariadb_password);

                return "mysql://{$encodedUser}:{$encodedPass}@{$this->uuid}:3306/{$this->mariadb_database}";
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
                    $encodedUser = rawurlencode($this->mariadb_user);
                    $encodedPass = rawurlencode($this->mariadb_password);

                    return "mysql://{$encodedUser}:{$encodedPass}@{$serverIp}:{$this->public_port}/{$this->mariadb_database}";
                }

                return null;
            }
        );
    }
}
