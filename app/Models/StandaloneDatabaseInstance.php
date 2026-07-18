<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;

/**
 * Shared base for all 8 Standalone* database engine models (Postgresql, Mysql, Mariadb,
 * Mongodb, Redis, Keydb, Dragonfly, Clickhouse). Was originally a plain interface
 * (`App\Contracts\StandaloneDatabaseInstance`) — converted to an abstract class (Phase 50)
 * specifically so PHPStan/Larastan can resolve `@property`/`@method` PHPDoc through it:
 * Larastan only resolves those annotations on classes, never on plain interfaces, so
 * every place code was typed as the old interface saw every property/method access
 * flagged "undefined" even though every real implementor had it. That gap is why so many
 * PHPStan baseline entries across this codebase carried a "StandaloneDatabaseInstance is a
 * known, accepted limitation" note — this class removes the limitation for every member
 * genuinely common to all 8 engines.
 *
 * Each engine still owns its own table, `$fillable`/`$casts`, `type()`, and
 * `internalDbUrl()`/`externalDbUrl()` (those genuinely differ per engine — this class is
 * never itself instantiated or queried). Engine-specific columns (credentials like
 * `postgres_user`/`redis_password`, per-engine config text, `ssl_mode`/`enable_ssl` on the
 * 3 engines that support it) intentionally stay off this docblock and still require a real
 * `instanceof StandaloneXxx` narrow to access — that's correct, not a remaining gap, since
 * those members genuinely don't exist on every engine.
 *
 * The properties/methods below were verified common by diffing all 8 engines' own
 * `@property` docblocks and keeping only the ones present, byte-identical, on all 8.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property string $status
 * @property string $image
 * @property string|null $ports_mappings
 * @property string $limits_memory
 * @property string $limits_memory_swap
 * @property int $limits_memory_swappiness
 * @property string $limits_memory_reservation
 * @property string $limits_cpus
 * @property string|null $limits_cpuset
 * @property int $limits_cpu_shares
 * @property string|null $custom_docker_run_options
 * @property bool $is_public
 * @property int|null $public_port
 * @property int|null $public_port_timeout
 * @property bool $is_log_drain_enabled
 * @property bool $health_check_enabled
 * @property int $health_check_interval
 * @property int $health_check_timeout
 * @property int $health_check_retries
 * @property int $health_check_start_period
 * @property string|null $started_at
 * @property string $last_online_at
 * @property Carbon|null $last_restart_at
 * @property string|null $last_restart_type
 * @property int $restart_count
 * @property string|null $config_hash
 * @property int $destination_id
 * @property string $destination_type
 * @property int|null $environment_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read StandaloneDocker|SwarmDocker|null $destination
 * @property-read Environment|null $environment
 * @property-read string $internal_db_url
 * @property-read string|null $external_db_url
 * @property-read string $database_type
 * @property-read bool $server_status
 * @property-read Collection<int, EnvironmentVariable> $environment_variables
 * @property-read int|null $environment_variables_count
 * @property-read Collection<int, EnvironmentVariable> $runtime_environment_variables
 * @property-read int|null $runtime_environment_variables_count
 * @property-read Collection<int, LocalFileVolume> $fileStorages
 * @property-read int|null $file_storages_count
 * @property-read Collection<int, LocalPersistentVolume> $persistentStorages
 * @property-read int|null $persistent_storages_count
 * @property-read Collection<int, ScheduledDatabaseBackup> $scheduledBackups
 * @property-read int|null $scheduled_backups_count
 * @property-read Collection<int, SslCertificate> $sslCertificates
 * @property-read int|null $ssl_certificates_count
 * @property-read Collection<int, Tag> $tags
 * @property-read int|null $tags_count
 *
 * @method MorphTo<Model, $this> destination()
 * @method BelongsTo<Environment, $this> environment()
 * @method MorphMany<EnvironmentVariable, $this> environment_variables()
 * @method MorphMany<EnvironmentVariable, $this> runtime_environment_variables()
 * @method MorphMany<LocalFileVolume, $this> fileStorages()
 * @method MorphMany<LocalPersistentVolume, $this> persistentStorages()
 * @method MorphMany<ScheduledDatabaseBackup, $this> scheduledBackups()
 * @method MorphMany<SslCertificate, $this> sslCertificates()
 * @method MorphToMany<Tag, $this> tags()
 * @method bool isConfigurationChanged(bool $save = false)
 * @method bool isRunning()
 * @method bool isExited()
 * @method bool isLogDrainEnabled()
 * @method string workdir()
 */
abstract class StandaloneDatabaseInstance extends BaseModel
{
    abstract public function type(): string;
}
