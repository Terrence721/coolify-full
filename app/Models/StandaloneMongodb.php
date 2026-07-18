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
use Illuminate\Support\Facades\Log;

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
 * @property string|null $mongo_conf
 * @property string $mongo_initdb_root_username
 * @property string $mongo_initdb_root_password
 * @property string $mongo_initdb_database
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereConfigHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereCustomDockerRunOptions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereDestinationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereDestinationType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereEnableSsl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereEnvironmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereHealthCheckEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereHealthCheckInterval($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereHealthCheckRetries($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereHealthCheckStartPeriod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereHealthCheckTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereIsIncludeTimestamps($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereIsLogDrainEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereIsPublic($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereLastOnlineAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereLastRestartAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereLastRestartType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereLimitsCpuShares($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereLimitsCpus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereLimitsCpuset($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereLimitsMemory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereLimitsMemoryReservation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereLimitsMemorySwap($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereLimitsMemorySwappiness($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereMongoConf($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereMongoInitdbDatabase($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereMongoInitdbRootPassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereMongoInitdbRootUsername($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb wherePortsMappings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb wherePublicPort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb wherePublicPortTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereRestartCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereSslMode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneMongodb withoutTrashed()
 *
 * @mixin \Eloquent
 */
class StandaloneMongodb extends StandaloneDatabaseInstance
{
    use ClearsGlobalSearchCache, HasDatabaseHealthCheck, HasFactory, HasMetrics, HasSafeStringAttribute, HasStandaloneDatabaseCommon, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'mongo_conf',
        'mongo_initdb_root_username',
        'mongo_initdb_root_password',
        'mongo_initdb_database',
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
        'public_port_timeout' => 'integer',
        'restart_count' => 'integer',
        'last_restart_at' => 'datetime',
        'last_restart_type' => 'string',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'mongodb-configdb-'.$database->uuid,
                'mount_path' => '/data/configdb',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
            ]);
            LocalPersistentVolume::create([
                'name' => 'mongodb-db-'.$database->uuid,
                'mount_path' => '/data/db',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
            ]);
        });
    }

    protected function configHashExtra(): string
    {
        return (string) $this->mongo_conf;
    }

    /**
     * @return Attribute<string, never>
     */
    public function mongoInitdbRootPassword(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                try {
                    return decrypt($value);
                } catch (\Throwable $th) {
                    Log::error('Unhandled exception in mongoInitdbRootPassword() closure.', ['error' => $th->getMessage()]);

                    $this->mongo_initdb_root_password = encrypt($value);
                    $this->save();

                    return $value;
                }
            }
        );
    }

    public function type(): string
    {
        return 'standalone-mongodb';
    }

    /**
     * @return Attribute<string, never>
     */
    protected function internalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                $encodedUser = rawurlencode($this->mongo_initdb_root_username);
                $encodedPass = rawurlencode($this->mongo_initdb_root_password);
                $url = "mongodb://{$encodedUser}:{$encodedPass}@{$this->uuid}:27017/?directConnection=true";
                if ($this->enable_ssl) {
                    $url .= '&tls=true&tlsCAFile=/etc/mongo/certs/ca.pem';
                    if (in_array($this->ssl_mode, ['verify-full'])) {
                        $url .= '&tlsCertificateKeyFile=/etc/mongo/certs/server.pem';
                    }
                }

                return $url;
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
                    $encodedUser = rawurlencode($this->mongo_initdb_root_username);
                    $encodedPass = rawurlencode($this->mongo_initdb_root_password);
                    $url = "mongodb://{$encodedUser}:{$encodedPass}@{$serverIp}:{$this->public_port}/?directConnection=true";
                    if ($this->enable_ssl) {
                        $url .= '&tls=true&tlsCAFile=/etc/mongo/certs/ca.pem';
                        if (in_array($this->ssl_mode, ['verify-full'])) {
                            $url .= '&tlsCertificateKeyFile=/etc/mongo/certs/server.pem';
                        }
                    }

                    return $url;
                }

                return null;
            }
        );
    }
}
