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
 * @property string $clickhouse_admin_user
 * @property string $clickhouse_admin_password
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
 * @property string $clickhouse_db
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereClickhouseAdminPassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereClickhouseAdminUser($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereClickhouseDb($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereConfigHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereCustomDockerRunOptions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereDestinationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereDestinationType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereEnvironmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereHealthCheckEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereHealthCheckInterval($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereHealthCheckRetries($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereHealthCheckStartPeriod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereHealthCheckTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereIsIncludeTimestamps($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereIsLogDrainEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereIsPublic($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereLastOnlineAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereLastRestartAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereLastRestartType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereLimitsCpuShares($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereLimitsCpus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereLimitsCpuset($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereLimitsMemory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereLimitsMemoryReservation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereLimitsMemorySwap($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereLimitsMemorySwappiness($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse wherePortsMappings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse wherePublicPort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse wherePublicPortTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereRestartCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneClickhouse withoutTrashed()
 *
 * @mixin \Eloquent
 */
class StandaloneClickhouse extends BaseModel implements StandaloneDatabaseInstance
{
    use ClearsGlobalSearchCache, HasDatabaseHealthCheck, HasFactory, HasMetrics, HasSafeStringAttribute, HasStandaloneDatabaseCommon, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'clickhouse_admin_user',
        'clickhouse_admin_password',
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
        'custom_docker_run_options',
        'clickhouse_db',
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
        'clickhouse_admin_password' => 'encrypted',
        'public_port_timeout' => 'integer',
        'restart_count' => 'integer',
        'last_restart_at' => 'datetime',
        'last_restart_type' => 'string',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'clickhouse-data-'.$database->uuid,
                'mount_path' => '/var/lib/clickhouse',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
            ]);
        });
    }

    public function type(): string
    {
        return 'standalone-clickhouse';
    }

    /**
     * @return Attribute<string, never>
     */
    protected function internalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                $encodedUser = rawurlencode($this->clickhouse_admin_user);
                $encodedPass = rawurlencode($this->clickhouse_admin_password);
                $database = $this->clickhouse_db ?? 'default';

                return "clickhouse://{$encodedUser}:{$encodedPass}@{$this->uuid}:9000/{$database}";
            },
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
                    $encodedUser = rawurlencode($this->clickhouse_admin_user);
                    $encodedPass = rawurlencode($this->clickhouse_admin_password);
                    $database = $this->clickhouse_db ?? 'default';

                    return "clickhouse://{$encodedUser}:{$encodedPass}@{$serverIp}:{$this->public_port}/{$database}";
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
