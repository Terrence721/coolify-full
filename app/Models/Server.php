<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProxyTypes;
use App\Events\ServerReachabilityChanged;
use App\Helpers\SslHelper;
use App\Jobs\CheckTraefikVersionForServerJob;
use App\Jobs\RegenerateSslCertJob;
use App\Notifications\Server\Reachable;
use App\Notifications\Server\Unreachable;
use App\Services\ConfigurationRepository;
use App\Support\ValidationPatterns;
use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasDockerContainers;
use App\Traits\HasMetrics;
use App\Traits\HasProxyConfiguration;
use App\Traits\HasSafeStringAttribute;
use App\Traits\HasSentinel;
use App\Traits\ValidatesDockerEnvironment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Stringable;
use OpenApi\Attributes as OA;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;
use Spatie\SchemalessAttributes\SchemalessAttributesTrait;
use Stevebauman\Purify\Facades\Purify;
use Visus\Cuid2\Cuid2;

/**
 * @property array{
 *     current: string,
 *     latest: string,
 *     type: 'patch_update'|'minor_upgrade',
 *     checked_at: string,
 *     newer_branch_target?: string,
 *     newer_branch_latest?: string,
 *     upgrade_target?: string
 * }|null $traefik_outdated_info Traefik version tracking information.
 *
 * This JSON column stores information about outdated Traefik proxy versions on this server.
 * The structure varies depending on the type of update available:
 *
 * **For patch updates** (e.g., 3.5.0 → 3.5.2):
 * ```php
 * [
 *     'current' => '3.5.0',              // Current version (without 'v' prefix)
 *     'latest' => '3.5.2',               // Latest patch version available
 *     'type' => 'patch_update',          // Update type identifier
 *     'checked_at' => '2025-11-14T10:00:00Z',  // ISO8601 timestamp
 *     'newer_branch_target' => 'v3.6',   // (Optional) Available major/minor version
 *     'newer_branch_latest' => '3.6.2'   // (Optional) Latest version in that branch
 * ]
 * ```
 *
 * **For minor/major upgrades** (e.g., 3.5.6 → 3.6.2):
 * ```php
 * [
 *     'current' => '3.5.6',              // Current version
 *     'latest' => '3.6.2',               // Latest version in target branch
 *     'type' => 'minor_upgrade',         // Update type identifier
 *     'upgrade_target' => 'v3.6',        // Target branch (with 'v' prefix)
 *     'checked_at' => '2025-11-14T10:00:00Z'  // ISO8601 timestamp
 * ]
 * ```
 *
 * **Null value**: Set to null when:
 * - Server is fully up-to-date with the latest version
 * - Traefik image uses the 'latest' tag (no fixed version tracking)
 * - No Traefik version detected on the server
 * @property-read ServerSetting $settings
 * @property-read PrivateKey|null $privateKey
 * @property-read CloudProviderToken|null $cloudProviderToken
 * @property-read Team|null $team
 * @property mixed $proxy
 * @property array<string, mixed>|null $outdatedInfo
 *
 * @see CheckTraefikVersionForServerJob Where this data is populated
 * @see Proxy Where this data is read and displayed
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property string $ip
 * @property int $port
 * @property string $user
 * @property int $team_id
 * @property int $private_key_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property bool $unreachable_notification_sent
 * @property int $unreachable_count
 * @property bool $high_disk_usage_notification_sent
 * @property bool $log_drain_notification_sent
 * @property int|null $swarm_cluster
 * @property string|null $validation_logs
 * @property string $sentinel_updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $ip_previous
 * @property int|null $hetzner_server_id
 * @property int|null $cloud_provider_token_id
 * @property string|null $hetzner_server_status
 * @property bool $is_validating
 * @property string|null $detected_traefik_version
 * @property array<array-key, mixed>|null $server_metadata
 * @property-read Collection<int, DockerCleanupExecution> $dockerCleanupExecutions
 * @property-read int|null $docker_cleanup_executions_count
 * @property-read Collection<int, SharedEnvironmentVariable> $environment_variables
 * @property-read int|null $environment_variables_count
 * @property-read mixed $get_ip
 * @property-read mixed $image
 * @property-read mixed $is_coolify_host
 * @property-read mixed $sanitized_name
 * @property-read Collection<int, Service> $services
 * @property-read int|null $services_count
 * @property-read Collection<int, SslCertificate> $sslCertificates
 * @property-read int|null $ssl_certificates_count
 * @property-read Collection<int, StandaloneDocker> $standaloneDockers
 * @property-read int|null $standalone_dockers_count
 * @property-read Collection<int, SwarmDocker> $swarmDockers
 * @property-read int|null $swarm_dockers_count
 *
 * @method static \Database\Factories\ServerFactory factory($count = null, $state = [])
 * @method static Builder<static>|Server newModelQuery()
 * @method static Builder<static>|Server newQuery()
 * @method static Builder<static>|Server onlyTrashed()
 * @method static Builder<static>|Server query()
 * @method static Builder<static>|Server whereCloudProviderTokenId($value)
 * @method static Builder<static>|Server whereCreatedAt($value)
 * @method static Builder<static>|Server whereDeletedAt($value)
 * @method static Builder<static>|Server whereDescription($value)
 * @method static Builder<static>|Server whereDetectedTraefikVersion($value)
 * @method static Builder<static>|Server whereHetznerServerId($value)
 * @method static Builder<static>|Server whereHetznerServerStatus($value)
 * @method static Builder<static>|Server whereHighDiskUsageNotificationSent($value)
 * @method static Builder<static>|Server whereId($value)
 * @method static Builder<static>|Server whereIp($value)
 * @method static Builder<static>|Server whereIpPrevious($value)
 * @method static Builder<static>|Server whereIsValidating($value)
 * @method static Builder<static>|Server whereLogDrainNotificationSent($value)
 * @method static Builder<static>|Server whereName($value)
 * @method static Builder<static>|Server wherePort($value)
 * @method static Builder<static>|Server wherePrivateKeyId($value)
 * @method static Builder<static>|Server whereProxy($value)
 * @method static Builder<static>|Server whereProxyType(string $proxyType)
 * @method static Builder<static>|Server whereSentinelUpdatedAt($value)
 * @method static Builder<static>|Server whereServerMetadata($value)
 * @method static Builder<static>|Server whereSwarmCluster($value)
 * @method static Builder<static>|Server whereTeamId($value)
 * @method static Builder<static>|Server whereTraefikOutdatedInfo($value)
 * @method static Builder<static>|Server whereUnreachableCount($value)
 * @method static Builder<static>|Server whereUnreachableNotificationSent($value)
 * @method static Builder<static>|Server whereUpdatedAt($value)
 * @method static Builder<static>|Server whereUser($value)
 * @method static Builder<static>|Server whereUuid($value)
 * @method static Builder<static>|Server whereValidationLogs($value)
 * @method static Builder<static>|Server withProxy()
 * @method static Builder<static>|Server withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Server withoutTrashed()
 *
 * @mixin \Eloquent
 */
#[OA\Schema(
    description: 'Server model',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', description: 'The server ID.'),
        new OA\Property(property: 'uuid', type: 'string', description: 'The server UUID.'),
        new OA\Property(property: 'name', type: 'string', description: 'The server name.'),
        new OA\Property(property: 'description', type: 'string', description: 'The server description.'),
        new OA\Property(property: 'ip', type: 'string', description: 'The IP address.'),
        new OA\Property(property: 'user', type: 'string', description: 'The user.'),
        new OA\Property(property: 'port', type: 'integer', description: 'The port number.'),
        new OA\Property(property: 'proxy', type: 'object', description: 'The proxy configuration.'),
        new OA\Property(property: 'proxy_type', type: 'string', enum: ['traefik', 'caddy', 'none'], description: 'The proxy type.'),
        new OA\Property(property: 'high_disk_usage_notification_sent', type: 'boolean', description: 'The flag to indicate if the high disk usage notification has been sent.'),
        new OA\Property(property: 'unreachable_notification_sent', type: 'boolean', description: 'The flag to indicate if the unreachable notification has been sent.'),
        new OA\Property(property: 'unreachable_count', type: 'integer', description: 'The unreachable count for your server.'),
        new OA\Property(property: 'validation_logs', type: 'string', description: 'The validation logs.'),
        new OA\Property(property: 'log_drain_notification_sent', type: 'boolean', description: 'The flag to indicate if the log drain notification has been sent.'),
        new OA\Property(property: 'swarm_cluster', type: 'string', description: 'The swarm cluster configuration.'),
        new OA\Property(property: 'settings', ref: '#/components/schemas/ServerSetting'),
    ]
)]

class Server extends BaseModel
{
    use ClearsGlobalSearchCache, HasDockerContainers, HasFactory, HasMetrics, HasProxyConfiguration, HasSentinel, SchemalessAttributesTrait, SoftDeletes, ValidatesDockerEnvironment;

    public static int $batch_counter = 0;

    /**
     * Identity map cache for request-scoped Server lookups.
     * Prevents N+1 queries when the same Server is accessed multiple times.
     */
    private static ?array $identityMapCache = null;

    protected $appends = ['is_coolify_host'];

    protected static function booted()
    {
        static::saving(function ($server) {
            $payload = [];
            if ($server->user) {
                $payload['user'] = str($server->user)->trim()->value();
            }
            if ($server->ip) {
                $payload['ip'] = str($server->ip)->trim()->value();

                // Update ip_previous when ip is being changed on an existing server
                if ($server->exists && $server->isDirty('ip') && $server->getOriginal('ip')) {
                    $payload['ip_previous'] = $server->getOriginal('ip');
                }
            }
            $server->fill($payload);
        });
        static::saved(function ($server) {
            if ($server->wasChanged('private_key_id') || $server->privateKey?->isDirty()) {
                refresh_server_connection($server->privateKey);
            }
        });
        static::created(function ($server) {
            ServerSetting::create([
                'server_id' => $server->id,
            ]);
            if ($server->id === 0) {
                if ($server->isSwarm()) {
                    (new SwarmDocker)->forceFill([
                        'id' => 0,
                        'name' => 'coolify',
                        'network' => 'coolify-overlay',
                        'server_id' => $server->id,
                    ])->save();
                } else {
                    (new StandaloneDocker)->forceFill($server->defaultStandaloneDockerAttributes(id: 0))->saveQuietly();
                }
            } else {
                if ($server->isSwarm()) {
                    SwarmDocker::create([
                        'name' => 'coolify-overlay',
                        'network' => 'coolify-overlay',
                        'server_id' => $server->id,
                    ]);
                } else {
                    $standaloneDocker = new StandaloneDocker;
                    $standaloneDocker->forceFill($server->defaultStandaloneDockerAttributes());
                    $standaloneDocker->saveQuietly();
                }
            }
            if (! isset($server->proxy->redirect_enabled)) {
                $server->proxy->redirect_enabled = true;
            }

            // Create predefined server shared variables
            SharedEnvironmentVariable::create([
                'key' => 'COOLIFY_SERVER_UUID',
                'value' => $server->uuid,
                'type' => 'server',
                'server_id' => $server->id,
                'team_id' => $server->team_id,
                'is_literal' => true,
            ]);
            SharedEnvironmentVariable::create([
                'key' => 'COOLIFY_SERVER_NAME',
                'value' => $server->name,
                'type' => 'server',
                'server_id' => $server->id,
                'team_id' => $server->team_id,
                'is_literal' => true,
            ]);
        });
        static::retrieved(function ($server) {
            if (! isset($server->proxy->redirect_enabled)) {
                $server->proxy->redirect_enabled = true;
            }
        });

        static::forceDeleting(function ($server) {
            $server->destinations()->each(function ($destination) {
                $destination->delete();
            });
            $server->settings()->delete();
            $server->sslCertificates()->delete();
        });

        static::updated(function () {
            static::flushIdentityMap();
        });
    }

    /**
     * Find a Server by ID using the identity map cache.
     * This prevents N+1 queries when the same Server is accessed multiple times.
     */
    public static function findCached(?int $id): ?static
    {
        if ($id === null) {
            return null;
        }

        if (static::$identityMapCache === null) {
            static::$identityMapCache = [];
        }

        if (! isset(static::$identityMapCache[$id])) {
            static::$identityMapCache[$id] = static::query()->find($id);
        }

        return static::$identityMapCache[$id];
    }

    /**
     * Flush the identity map cache.
     * Called automatically on update, and should be called in tests.
     */
    public static function flushIdentityMap(): void
    {
        static::$identityMapCache = null;
    }

    protected $casts = [
        'proxy' => SchemalessAttributes::class,
        'traefik_outdated_info' => 'array',
        'server_metadata' => 'array',
        'logdrain_axiom_api_key' => 'encrypted',
        'logdrain_newrelic_license_key' => 'encrypted',
        'delete_unused_volumes' => 'boolean',
        'delete_unused_networks' => 'boolean',
        'unreachable_notification_sent' => 'boolean',
        'is_build_server' => 'boolean',
        'force_disabled' => 'boolean',
    ];

    protected array $schemalessAttributes = [
        'proxy',
    ];

    protected $fillable = [
        'name',
        'ip',
        'port',
        'user',
        'description',
        'private_key_id',
        'cloud_provider_token_id',
        'team_id',
        'hetzner_server_id',
        'hetzner_server_status',
        'is_validating',
        'detected_traefik_version',
        'traefik_outdated_info',
        'server_metadata',
        'ip_previous',
    ];

    use HasSafeStringAttribute;

    public function setValidationLogsAttribute(?string $value): void
    {
        $this->attributes['validation_logs'] = $value !== null
            ? Purify::config('validation_logs')->clean($value)
            : null;
    }

    public function type(): string
    {
        return 'server';
    }

    protected function isCoolifyHost(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->id === 0;
            }
        );
    }

    /**
     * @return Builder<Server>
     */
    public static function isReachable(): Builder
    {
        return Server::ownedByCurrentTeam()->whereRelation('settings', 'is_reachable', true);
    }

    /**
     * Get query builder for servers owned by current team.
     * If you need all servers without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    /**
     * @param  array<int, string>  $select
     * @return Builder<Server>
     */
    public static function ownedByCurrentTeam(array $select = ['*']): Builder
    {
        $team = currentTeam();
        $selectArray = collect($select)->concat(['id']);

        if (! $team) {
            return Server::query()
                ->whereRaw('1 = 0')
                ->with('settings', 'swarmDockers', 'standaloneDockers')
                ->select($selectArray->all())
                ->orderBy('name');
        }

        return Server::whereTeamId($team->id)->with('settings', 'swarmDockers', 'standaloneDockers')->select($selectArray->all())->orderBy('name');
    }

    /**
     * Get all servers owned by current team (cached for request duration).
     */
    /**
     * @return Collection<int, Server>
     */
    public static function ownedByCurrentTeamCached(): Collection
    {
        return once(function () {
            return Server::ownedByCurrentTeam()->get();
        });
    }

    /**
     * @return Builder<Server>
     */
    public static function isUsable(): Builder
    {
        return Server::ownedByCurrentTeam()->whereRelation('settings', 'is_reachable', true)->whereRelation('settings', 'is_usable', true)->whereRelation('settings', 'is_swarm_worker', false)->whereRelation('settings', 'is_build_server', false)->whereRelation('settings', 'force_disabled', false);
    }

    /**
     * @return HasOne<ServerSetting, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(ServerSetting::class);
    }

    /**
     * @return HasMany<DockerCleanupExecution, $this>
     */
    public function dockerCleanupExecutions(): HasMany
    {
        return $this->hasMany(DockerCleanupExecution::class);
    }

    public function scopeWithProxy(): Builder
    {
        return $this->proxy->modelScope();
    }

    public function scopeWhereProxyType(Builder $query, string $proxyType): Builder
    {
        return $query->where('proxy->type', $proxyType);
    }

    public function isLocalhost(): bool
    {
        return $this->ip === 'host.docker.internal' || $this->id === 0;
    }

    /**
     * @return Builder<Server>
     */
    public static function buildServers(int $teamId): Builder
    {
        return Server::whereTeamId($teamId)->whereRelation('settings', 'is_reachable', true)->whereRelation('settings', 'is_build_server', true);
    }

    public function isForceDisabled(): bool
    {
        return $this->settings->force_disabled;
    }

    public function forceEnableServer(): void
    {
        $this->settings->force_disabled = false;
        $this->settings->save();
    }

    public function forceDisableServer(): void
    {
        $this->settings->force_disabled = true;
        $this->settings->save();
        $sshKeyFileLocation = "id.root@{$this->uuid}";
        Storage::disk('ssh-keys')->delete($sshKeyFileLocation);
        $this->disableSshMux();
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function port(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                return (int) preg_replace('/[^0-9]/', '', (string) $value);
            },
            set: function ($value) {
                return (int) preg_replace('/[^0-9]/', '', (string) $value);
            }
        );
    }

    public function user(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                return $value === null ? null : preg_replace(ValidationPatterns::INVALID_SERVER_USERNAME_CHARACTERS_PATTERN, '', $value);
            },
            set: function ($value) {
                return $value === null ? null : preg_replace(ValidationPatterns::INVALID_SERVER_USERNAME_CHARACTERS_PATTERN, '', $value);
            }
        );
    }

    public function ip(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                return preg_replace('/[^0-9a-zA-Z.:%-]/', '', $value);
            },
            set: function ($value) {
                return preg_replace('/[^0-9a-zA-Z.:%-]/', '', $value);
            }
        );
    }

    public function getIp(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (isDev()) {
                    return '127.0.0.1';
                }
                if ($this->isLocalhost()) {
                    return base_ip();
                }

                return $this->ip;
            }
        );
    }

    public function previews()
    {
        return $this->destinations()->map(function ($standaloneDocker) {
            return $standaloneDocker->applications->map(function ($application) {
                return $application->previews;
            })->flatten();
        })->flatten();
    }

    public function destinations()
    {
        $standalone_docker = $this->hasMany(StandaloneDocker::class)->get();
        $swarm_docker = $this->hasMany(SwarmDocker::class)->get();

        return $standalone_docker->concat($swarm_docker);
    }

    /**
     * @return HasMany<StandaloneDocker, $this>
     */
    public function standaloneDockers(): HasMany
    {
        return $this->hasMany(StandaloneDocker::class);
    }

    /**
     * @return HasMany<SwarmDocker, $this>
     */
    public function swarmDockers(): HasMany
    {
        return $this->hasMany(SwarmDocker::class);
    }

    /**
     * @return BelongsTo<PrivateKey, $this>
     */
    public function privateKey(): BelongsTo
    {
        return $this->belongsTo(PrivateKey::class);
    }

    /**
     * @return BelongsTo<CloudProviderToken, $this>
     */
    public function cloudProviderToken(): BelongsTo
    {
        return $this->belongsTo(CloudProviderToken::class);
    }

    /**
     * @return HasMany<SslCertificate, $this>
     */
    public function sslCertificates(): HasMany
    {
        return $this->hasMany(SslCertificate::class);
    }

    public function muxFilename(): string
    {
        return 'mux_'.$this->uuid;
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return array{id?: int, name: string, uuid: string, network: string, server_id: int}
     */
    public function defaultStandaloneDockerAttributes(?int $id = null): array
    {
        $attributes = [
            'name' => 'coolify',
            'uuid' => (string) new Cuid2,
            'network' => 'coolify',
            'server_id' => $this->id,
        ];

        if (! is_null($id)) {
            $attributes['id'] = $id;
        }

        return $attributes;
    }

    /**
     * @return HasMany<SharedEnvironmentVariable, $this>
     */
    public function environment_variables(): HasMany
    {
        return $this->hasMany(SharedEnvironmentVariable::class)->where('type', 'server');
    }

    public function isProxyShouldRun(): bool
    {
        // TODO: Do we need "|| $this->proxy->force_stop" here?
        if ($this->proxyType() === ProxyTypes::NONE->value || $this->isBuildServer()) {
            return false;
        }

        return true;
    }

    public function skipServer(): bool
    {
        if ($this->ip === '1.2.3.4') {
            return true;
        }
        if ($this->settings->force_disabled === true) {
            return true;
        }

        return false;
    }

    public function isFunctional(): bool
    {
        $isFunctional = data_get($this->settings, 'is_reachable') && data_get($this->settings, 'is_usable') && data_get($this->settings, 'force_disabled') === false && $this->ip !== '1.2.3.4';

        if ($isFunctional === false) {
            Storage::disk('ssh-mux')->delete($this->muxFilename());
        }

        return $isFunctional;
    }

    public function isLogDrainEnabled(): bool
    {
        return $this->settings->is_logdrain_newrelic_enabled || $this->settings->is_logdrain_highlight_enabled || $this->settings->is_logdrain_axiom_enabled || $this->settings->is_logdrain_custom_enabled;
    }

    public function validateOS(): bool|Stringable
    {
        $os_release = instant_remote_process(['cat /etc/os-release'], $this);
        $releaseLines = collect(explode("\n", $os_release));
        $collectedData = collect([]);
        foreach ($releaseLines as $line) {
            $item = str($line)->trim();
            $collectedData->put($item->before('=')->value(), $item->after('=')->lower()->replace('"', '')->value());
        }
        $ID = data_get($collectedData, 'ID');
        // $ID_LIKE = data_get($collectedData, 'ID_LIKE');
        // $VERSION_ID = data_get($collectedData, 'VERSION_ID');
        $supported = collect(SUPPORTED_OS)->filter(function ($supportedOs) use ($ID) {
            if (str($supportedOs)->contains($ID)) {
                return str($ID);
            }
        });
        if ($supported->count() === 1) {
            return str($supported->first());
        } else {
            return false;
        }
    }

    public function isTerminalEnabled(): bool
    {
        return $this->settings->is_terminal_enabled ?? false;
    }

    public function isSwarm(): bool
    {
        return (bool) data_get($this, 'settings.is_swarm_manager') || (bool) data_get($this, 'settings.is_swarm_worker');
    }

    public function isSwarmManager(): bool
    {
        return (bool) data_get($this, 'settings.is_swarm_manager');
    }

    public function isSwarmWorker(): bool
    {
        return (bool) data_get($this, 'settings.is_swarm_worker');
    }

    public function serverStatus(): bool
    {
        if ($this->status() === false) {
            return false;
        }
        if ($this->isFunctional() === false) {
            return false;
        }

        return true;
    }

    public function status(): bool
    {
        ['uptime' => $uptime] = $this->validateConnection();
        if ($uptime === false) {
            foreach ($this->applications() as $application) {
                $application->status = 'exited';
                $application->save();
            }
            foreach ($this->databases() as $database) {
                $database->status = 'exited';
                $database->save();
            }
            foreach ($this->services() as $service) {
                $apps = $service->applications()->get();
                $dbs = $service->databases()->get();
                foreach ($apps as $app) {
                    $app->status = 'exited';
                    $app->save();
                }
                foreach ($dbs as $db) {
                    $db->status = 'exited';
                    $db->save();
                }
            }

            return false;
        }

        return true;
    }

    public function isReachableChanged(): void
    {
        $this->refresh();
        $unreachableNotificationSent = (bool) $this->unreachable_notification_sent;
        $isReachable = (bool) $this->settings->is_reachable;

        if ($isReachable === true) {
            if ($unreachableNotificationSent === true) {
                $this->sendReachableNotification();
            }

            return;
        }

        if ($this->unreachable_count >= 2 && ! $unreachableNotificationSent) {
            $this->sendUnreachableNotification();
        }
    }

    public function sendReachableNotification(): void
    {
        $this->unreachable_notification_sent = false;
        $this->save();
        $this->refresh();
        $this->team->notify(new Reachable($this));
    }

    public function sendUnreachableNotification(): void
    {
        $this->unreachable_notification_sent = true;
        $this->save();
        $this->refresh();
        $this->team->notify(new Unreachable($this));
    }

    /**
     * @return array{uptime: bool, error: ?string}
     */
    public function validateConnection(bool $justCheckingNewKey = false): array
    {
        $this->disableSshMux();

        if ($this->skipServer()) {
            return ['uptime' => false, 'error' => 'Server skipped.'];
        }
        try {
            instant_remote_process(['ls /'], $this);
            if ($this->settings->is_reachable === false) {
                $this->settings->is_reachable = true;
                $this->settings->save();
                ServerReachabilityChanged::dispatch($this);
            }

            return ['uptime' => true, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in validateConnection().', ['error' => $e->getMessage()]);

            if ($justCheckingNewKey) {
                return ['uptime' => false, 'error' => 'This key is not valid for this server.'];
            }
            if ($this->settings->is_reachable === true) {
                $this->settings->is_reachable = false;
                $this->settings->save();
                ServerReachabilityChanged::dispatch($this);
            }

            return ['uptime' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createWithPrivateKey(array $data, PrivateKey $privateKey): self
    {
        $server = new self($data);
        $server->privateKey()->associate($privateKey);
        $server->save();

        return $server;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateWithPrivateKey(array $data, ?PrivateKey $privateKey = null): self
    {
        $this->update($data);
        if ($privateKey) {
            $this->privateKey()->associate($privateKey);
            $this->save();
        }

        return $this;
    }

    public function storageCheck(): ?string
    {
        $commands = [
            'df / --output=pcent | tr -cd 0-9',
        ];

        return instant_remote_process($commands, $this, false);
    }

    public function isIpv6(): bool
    {
        return str($this->ip)->contains(':');
    }

    public function url(): string
    {
        return base_url().'/server/'.$this->uuid;
    }

    public function restartContainer(string $containerName): ?string
    {
        return instant_remote_process(['docker restart '.escapeshellarg($containerName)], $this, false);
    }

    public function isEmpty(): bool
    {
        return $this->applications()->count() == 0 &&
            $this->databases()->count() == 0 &&
            $this->services()->count() == 0;
    }

    private function disableSshMux(): void
    {
        $configRepository = app(ConfigurationRepository::class);
        $configRepository->disableSshMux();
    }

    public function generateCaCertificate()
    {
        try {
            ray('Generating CA certificate for server', $this->id);
            SslHelper::generateSslCertificate(
                commonName: 'Coolify CA Certificate',
                serverId: $this->id,
                isCaCertificate: true,
                validityDays: 10 * 365
            );
            $caCertificate = $this->sslCertificates()->where('is_ca_certificate', true)->first();
            ray('CA certificate generated', $caCertificate);
            if ($caCertificate) {
                $certificateContent = $caCertificate->ssl_certificate;
                $caCertPath = config('constants.coolify.base_config_path').'/ssl/';

                $base64Cert = base64_encode($certificateContent);

                $commands = collect([
                    "mkdir -p $caCertPath",
                    "chown -R 9999:root $caCertPath",
                    "chmod -R 700 $caCertPath",
                    "rm -rf $caCertPath/coolify-ca.crt",
                    "echo '{$base64Cert}' | base64 -d | tee $caCertPath/coolify-ca.crt > /dev/null",
                    "chmod 644 $caCertPath/coolify-ca.crt",
                ]);

                instant_remote_process($commands, $this, false);

                dispatch(new RegenerateSslCertJob(
                    server_id: $this->id,
                    force_regeneration: true
                ));
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in generateCaCertificate().', ['error' => $e->getMessage()]);

            return handleError($e);
        }
    }
}
