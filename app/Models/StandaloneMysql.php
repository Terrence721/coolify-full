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
 * @property string $mysql_root_password
 * @property string $mysql_user
 * @property string $mysql_password
 * @property string $mysql_database
 * @property string|null $mysql_conf
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereConfigHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereCustomDockerRunOptions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereDestinationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereDestinationType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereEnableSsl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereEnvironmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereHealthCheckEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereHealthCheckInterval($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereHealthCheckRetries($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereHealthCheckStartPeriod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereHealthCheckTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereIsIncludeTimestamps($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereIsLogDrainEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereIsPublic($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereLastOnlineAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereLastRestartAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereLastRestartType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereLimitsCpuShares($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereLimitsCpus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereLimitsCpuset($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereLimitsMemory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereLimitsMemoryReservation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereLimitsMemorySwap($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereLimitsMemorySwappiness($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereMysqlConf($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereMysqlDatabase($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereMysqlPassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereMysqlRootPassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereMysqlUser($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql wherePortsMappings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql wherePublicPort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql wherePublicPortTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereRestartCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereSslMode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMysql withoutTrashed()
 *
 * @mixin \Eloquent
 */
class StandaloneMysql extends BaseModel
{
    use ClearsGlobalSearchCache, HasDatabaseHealthCheck, HasFactory, HasMetrics, HasSafeStringAttribute, HasStandaloneDatabaseCommon, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'mysql_root_password',
        'mysql_user',
        'mysql_password',
        'mysql_database',
        'mysql_conf',
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
        'mysql_password' => 'encrypted',
        'mysql_root_password' => 'encrypted',
        'public_port_timeout' => 'integer',
        'restart_count' => 'integer',
        'last_restart_at' => 'datetime',
        'last_restart_type' => 'string',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'mysql-data-'.$database->uuid,
                'mount_path' => '/var/lib/mysql',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
            ]);
        });
    }

    protected function configHashExtra(): string
    {
        return (string) $this->mysql_conf;
    }

    public function type(): string
    {
        return 'standalone-mysql';
    }

    protected function internalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                $encodedUser = rawurlencode($this->mysql_user);
                $encodedPass = rawurlencode($this->mysql_password);
                $url = "mysql://{$encodedUser}:{$encodedPass}@{$this->uuid}:3306/{$this->mysql_database}";
                if ($this->enable_ssl) {
                    $url .= "?ssl-mode={$this->ssl_mode}";
                    if (in_array($this->ssl_mode, ['VERIFY_CA', 'VERIFY_IDENTITY'])) {
                        $url .= '&ssl-ca=/etc/ssl/certs/coolify-ca.crt';
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
                    $encodedUser = rawurlencode($this->mysql_user);
                    $encodedPass = rawurlencode($this->mysql_password);
                    $url = "mysql://{$encodedUser}:{$encodedPass}@{$serverIp}:{$this->public_port}/{$this->mysql_database}";
                    if ($this->enable_ssl) {
                        $url .= "?ssl-mode={$this->ssl_mode}";
                        if (in_array($this->ssl_mode, ['VERIFY_CA', 'VERIFY_IDENTITY'])) {
                            $url .= '&ssl-ca=/etc/ssl/certs/coolify-ca.crt';
                        }
                    }

                    return $url;
                }

                return null;
            }
        );
    }
}
