<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProcessStatus;
use App\Services\ContainerStatusAggregator;
use App\Services\ServiceExtraFieldsResolver;
use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasResourceCleanup;
use App\Traits\HasResourceLinks;
use App\Traits\HasResourceStatus;
use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Yaml\Yaml;
use Visus\Cuid2\Cuid2;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property-read bool $isDeployable
 * @property int $environment_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $server_id
 * @property string|null $description
 * @property string $docker_compose_raw
 * @property string|null $docker_compose
 * @property string|null $destination_type
 * @property int|null $destination_id
 * @property Carbon|null $deleted_at
 * @property bool $connect_to_docker_network
 * @property string|null $config_hash
 * @property string|null $service_type
 * @property bool $is_container_label_escape_enabled
 * @property string $compose_parsing_version
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ServiceApplication> $applications
 * @property-read int|null $applications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ServiceDatabase> $databases
 * @property-read int|null $databases_count
 * @property-read StandaloneDocker|SwarmDocker|null $destination
 * @property-read Environment|null $environment
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EnvironmentVariable> $environment_variables
 * @property-read int|null $environment_variables_count
 * @property-read string $status
 * @property-read mixed $image
 * @property-read mixed $is_deployable
 * @property-read mixed $sanitized_name
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ScheduledTask> $scheduled_tasks
 * @property-read int|null $scheduled_tasks_count
 * @property-read Server|null $server
 * @property-read mixed $server_status
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Tag> $tags
 * @property-read int|null $tags_count
 *
 * @method static \Database\Factories\ServiceFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereComposeParsingVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereConfigHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereConnectToDockerNetwork($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereDestinationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereDestinationType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereDockerCompose($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereDockerComposeRaw($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereEnvironmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereIsContainerLabelEscapeEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereServerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereServiceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service withoutTrashed()
 *
 * @mixin \Eloquent
 */
#[OA\Schema(
    description: 'Service model',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', description: 'The unique identifier of the service. Only used for database identification.'),
        new OA\Property(property: 'uuid', type: 'string', description: 'The unique identifier of the service.'),
        new OA\Property(property: 'name', type: 'string', description: 'The name of the service.'),
        new OA\Property(property: 'environment_id', type: 'integer', description: 'The unique identifier of the environment where the service is attached to.'),
        new OA\Property(property: 'server_id', type: 'integer', description: 'The unique identifier of the server where the service is running.'),
        new OA\Property(property: 'description', type: 'string', description: 'The description of the service.'),
        new OA\Property(property: 'docker_compose_raw', type: 'string', description: 'The raw docker-compose.yml file of the service.'),
        new OA\Property(property: 'docker_compose', type: 'string', description: 'The docker-compose.yml file that is parsed and modified by Coolify.'),
        new OA\Property(property: 'destination_type', type: 'string', description: 'Destination type.'),
        new OA\Property(property: 'destination_id', type: 'integer', description: 'The unique identifier of the destination where the service is running.'),
        new OA\Property(property: 'connect_to_docker_network', type: 'boolean', description: 'The flag to connect the service to the predefined Docker network.'),
        new OA\Property(property: 'is_container_label_escape_enabled', type: 'boolean', description: 'The flag to enable the container label escape.'),
        new OA\Property(property: 'is_container_label_readonly_enabled', type: 'boolean', description: 'The flag to enable the container label readonly.'),
        new OA\Property(property: 'config_hash', type: 'string', description: 'The hash of the service configuration.'),
        new OA\Property(property: 'service_type', type: 'string', description: 'The type of the service.'),
        new OA\Property(property: 'created_at', type: 'string', description: 'The date and time when the service was created.'),
        new OA\Property(property: 'updated_at', type: 'string', description: 'The date and time when the service was last updated.'),
        new OA\Property(property: 'deleted_at', type: 'string', description: 'The date and time when the service was deleted.'),
    ],
)]
class Service extends BaseModel
{
    use ClearsGlobalSearchCache, HasFactory, HasResourceCleanup, HasResourceLinks, HasResourceStatus, HasSafeStringAttribute, SoftDeletes;

    protected function resourceTypeSlug(): string
    {
        return 'service';
    }

    /**
     * ApplicationSetting already casts these same column names as boolean; no strict
     * comparisons exist on either, so the casts only normalize SQLite's raw ints.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'connect_to_docker_network' => 'boolean',
            'is_container_label_escape_enabled' => 'boolean',
        ];
    }

    private static string $parserVersion = '5';

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'docker_compose_raw',
        'docker_compose',
        'connect_to_docker_network',
        'service_type',
        'config_hash',
        'compose_parsing_version',
        'is_container_label_escape_enabled',
        'environment_id',
        'server_id',
        'destination_id',
        'destination_type',
    ];

    protected $appends = ['server_status', 'status'];

    protected static function booted()
    {
        static::creating(function ($service) {
            if (blank($service->name)) {
                $service->name = 'service-'.(new Cuid2);
            }
        });
        static::created(function ($service) {
            $service->compose_parsing_version = self::$parserVersion;
            $service->save();
        });
    }

    public function isConfigurationChanged(bool $save = false): bool
    {
        $domains = $this->applications()->get()->pluck('fqdn')->sort()->toArray();
        $domains = implode(',', $domains);

        $applicationImages = $this->applications()->get()->pluck('image')->sort();
        $databaseImages = $this->databases()->get()->pluck('image')->sort();
        $images = $applicationImages->merge($databaseImages);
        $images = implode(',', $images->toArray());

        $applicationStorages = $this->applications()->get()->pluck('persistentStorages')->flatten()->sortBy('id');
        $databaseStorages = $this->databases()->get()->pluck('persistentStorages')->flatten()->sortBy('id');
        $storages = $applicationStorages->merge($databaseStorages)->implode('updated_at');

        $newConfigHash = $images.$domains.$images.$storages;
        $newConfigHash .= json_encode($this->environment_variables()->pluck('value')->sort()->values());
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

    /**
     * @return Attribute<bool, never>
     */
    protected function serverStatus(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->server?->isFunctional() ?? false;
            }
        );
    }

    public function isStarting(): bool
    {
        try {
            $activity = Activity::where('properties->type_uuid', $this->uuid)->latest()->first();
            $status = data_get($activity, 'properties.status');

            return $status === ProcessStatus::QUEUED->value || $status === ProcessStatus::IN_PROGRESS->value;
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in isStarting().', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function type(): string
    {
        return 'service';
    }

    public function project(): mixed
    {
        return data_get($this, 'environment.project');
    }

    public function team(): mixed
    {
        return data_get($this, 'environment.project.team');
    }

    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * Get query builder for services owned by current team.
     * If you need all services without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    /**
     * @return Builder<Service>
     */
    public static function ownedByCurrentTeam(): Builder
    {
        return Service::whereRelation('environment.project.team', 'id', currentTeam()->id)->orderBy('name');
    }

    /**
     * Get all services owned by current team (cached for request duration).
     *
     * @return Collection<int, Service>
     */
    public static function ownedByCurrentTeamCached(): Collection
    {
        return once(function () {
            return Service::ownedByCurrentTeam()->get();
        });
    }

    /**
     * Calculate the service's aggregate status from its applications and databases.
     *
     * This method aggregates status from Eloquent model relationships (not Docker containers).
     * It differs from the CalculatesExcludedStatus trait which works with Docker container objects
     * during container inspection. This accessor runs on-demand for UI display and works with
     * already-stored status strings from the database.
     *
     * Status format: "{status}:{health}" or "{status}:{health}:excluded"
     * - Status values: running, exited, degraded, starting, paused, restarting
     * - Health values: healthy, unhealthy, unknown
     * - :excluded suffix: Indicates all containers are excluded from health monitoring
     *
     * @return string The aggregate status in format "status:health" or "status:health:excluded"
     */
    public function getStatusAttribute()
    {
        if ($this->isStarting()) {
            return 'starting:unhealthy';
        }

        $applications = $this->applications;
        $databases = $this->databases;

        [$complexStatus, $complexHealth, $hasNonExcluded] = $this->aggregateResourceStatuses(
            $applications,
            $databases,
            excludedOnly: false
        );

        // If all services are excluded from status checks, calculate status from excluded containers
        // but mark it with :excluded to indicate monitoring is disabled
        if (! $hasNonExcluded && ($complexStatus === null && $complexHealth === null)) {
            [$excludedStatus, $excludedHealth] = $this->aggregateResourceStatuses(
                $applications,
                $databases,
                excludedOnly: true
            );

            // Return status with :excluded suffix to indicate monitoring is disabled
            if ($excludedStatus && $excludedHealth) {
                return "{$excludedStatus}:{$excludedHealth}:excluded";
            }

            // If no status was calculated at all (no containers exist), return unknown
            if ($excludedStatus === null && $excludedHealth === null) {
                return 'unknown:unknown:excluded';
            }

            return 'exited';
        }

        // If health is null/empty, return just the status without trailing colon
        if ($complexHealth === null || $complexHealth === '') {
            return $complexStatus;
        }

        return "{$complexStatus}:{$complexHealth}";
    }

    /**
     * Aggregate status and health from collections of applications and databases.
     *
     * This helper method consolidates status aggregation logic using ContainerStatusAggregator.
     * It processes container status strings stored in the database (not live Docker data).
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, ServiceApplication>  $applications  Collection of Application models
     * @param  \Illuminate\Database\Eloquent\Collection<int, ServiceDatabase>  $databases  Collection of Database models
     * @param  bool  $excludedOnly  If true, only process excluded containers; if false, only process non-excluded
     * @return array{0: string|null, 1: string|null, 2?: bool} [status, health, hasNonExcluded (only when excludedOnly=false)]
     */
    private function aggregateResourceStatuses($applications, $databases, bool $excludedOnly = false): array
    {
        $hasNonExcluded = false;
        $statusStrings = collect();

        // Process both applications and databases using the same logic
        $resources = $applications->concat($databases);

        foreach ($resources as $resource) {
            $isExcluded = $resource->exclude_from_status || str($resource->status)->contains(':excluded');

            // Filter based on excludedOnly flag
            if ($excludedOnly && ! $isExcluded) {
                continue;
            }
            if (! $excludedOnly && $isExcluded) {
                continue;
            }

            if (! $excludedOnly) {
                $hasNonExcluded = true;
            }

            // Strip :excluded suffix before aggregation (it's in the 3rd part of "status:health:excluded")
            $status = str($resource->status)->before(':excluded')->toString();
            $statusStrings->push($status);
        }

        // If no status strings collected, return nulls
        if ($statusStrings->isEmpty()) {
            return $excludedOnly ? [null, null] : [null, null, $hasNonExcluded];
        }

        // Use ContainerStatusAggregator service for state machine logic
        $aggregator = new ContainerStatusAggregator;
        $aggregatedStatus = $aggregator->aggregateFromStrings($statusStrings);

        // Parse the aggregated "status:health" string
        $parts = explode(':', $aggregatedStatus);
        $status = $parts[0] ?? null;
        $health = $parts[1] ?? null;

        if ($excludedOnly) {
            return [$status, $health];
        }

        return [$status, $health, $hasNonExcluded];
    }

    /**
     * @return Collection<string, mixed>
     */
    public function extraFields(): Collection
    {
        return (new ServiceExtraFieldsResolver)->resolve($this);
    }

    /**
     * @param  iterable<array{key: mixed, value: mixed}>  $fields
     */
    public function saveExtraFields($fields): void
    {
        (new ServiceExtraFieldsResolver)->save($this, $fields);
    }

    public function documentation(): string
    {
        $services = get_service_templates();
        $service = data_get($services, str($this->name)->beforeLast('-')->value, []);

        return data_get($service, 'documentation', config('constants.urls.docs'));
    }

    /**
     * Get the required port for this service from the template definition.
     */
    public function getRequiredPort(): ?int
    {
        try {
            $services = get_service_templates();
            $serviceName = str($this->name)->beforeLast('-')->value();
            $service = data_get($services, $serviceName, []);
            $port = data_get($service, 'port');

            return $port ? (int) $port : null;
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in getRequiredPort().', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Check if this service requires a port to function correctly.
     */
    public function requiresPort(): bool
    {
        return $this->getRequiredPort() !== null;
    }

    /**
     * @return HasMany<ServiceApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(ServiceApplication::class);
    }

    /**
     * @return HasMany<ServiceDatabase, $this>
     */
    public function databases(): HasMany
    {
        return $this->hasMany(ServiceDatabase::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function destination(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function byUuid(string $uuid): ServiceApplication|ServiceDatabase|null
    {
        $app = $this->applications()->whereUuid($uuid)->first();
        if ($app) {
            return $app;
        }
        $db = $this->databases()->whereUuid($uuid)->first();
        if ($db) {
            return $db;
        }

        return null;
    }

    public function byName(string $name): ServiceApplication|ServiceDatabase|null
    {
        $app = $this->applications()->whereName($name)->first();
        if ($app) {
            return $app;
        }
        $db = $this->databases()->whereName($name)->first();
        if ($db) {
            return $db;
        }

        return null;
    }

    /**
     * @return HasMany<ScheduledTask, $this>
     */
    public function scheduled_tasks(): HasMany
    {
        return $this->hasMany(ScheduledTask::class)->orderBy('name', 'asc');
    }

    /**
     * @return MorphMany<EnvironmentVariable, $this>
     */
    public function environment_variables(): MorphMany
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable');
    }

    public function workdir(): string
    {
        return service_configuration_dir()."/{$this->uuid}";
    }

    public function saveComposeConfigs(): void
    {
        // Guard against null or empty docker_compose
        if (! $this->docker_compose) {
            return;
        }

        $workdir = $this->workdir();

        instant_remote_process([
            "mkdir -p $workdir",
            "cd $workdir",
        ], $this->server);

        $filename = new Cuid2.'-docker-compose.yml';
        Storage::disk('local')->put("tmp/{$filename}", $this->docker_compose);
        $path = Storage::path("tmp/{$filename}");
        instant_scp($path, "{$workdir}/docker-compose.yml", $this->server);
        Storage::disk('local')->delete("tmp/{$filename}");

        $commands[] = "cd $workdir";
        $commands[] = 'rm -f .env || true';

        $envs = collect([]);

        // Generate SERVICE_NAME_* environment variables from docker-compose services
        if ($this->docker_compose) {
            try {
                $dockerCompose = Yaml::parse($this->docker_compose);
                $services = data_get($dockerCompose, 'services', []);
                foreach ($services as $serviceName => $_) {
                    $envs->push('SERVICE_NAME_'.str($serviceName)->replace('-', '_')->replace('.', '_')->upper().'='.$serviceName);
                }
            } catch (\Exception $e) {
                ray($e->getMessage());
            }
        }

        $envs_from_coolify = $this->environment_variables()->get();
        $sorted = $envs_from_coolify->sortBy(function ($env) {
            if (str($env->key)->startsWith('SERVICE_')) {
                return 1;
            }
            if (str($env->value)->startsWith('$SERVICE_') || str($env->value)->startsWith('${SERVICE_')) {
                return 2;
            }

            return 3;
        });
        foreach ($sorted as $env) {
            $envs->push("{$env->key}={$env->real_value}");
        }
        if ($envs->count() === 0) {
            $commands[] = 'touch .env';
        } else {
            $envs_base64 = base64_encode($envs->implode("\n"));
            $commands[] = "echo '$envs_base64' | base64 -d | tee .env > /dev/null";
        }

        instant_remote_process($commands, $this->server);
    }

    /**
     * @return Collection<int, mixed>
     */
    public function parse(bool $isNew = false): Collection
    {
        if ((int) $this->compose_parsing_version >= 3) {
            return serviceParser($this);
        } elseif ($this->docker_compose_raw) {
            return parseDockerComposeFile($this, $isNew);
        } else {
            return collect([]);
        }
    }

    public function networks()
    {
        return getTopLevelNetworks($this);
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isDeployable(): Attribute
    {
        return Attribute::make(
            get: function () {
                $envs = $this->environment_variables()->where('is_required', true)->get();
                foreach ($envs as $env) {
                    if ($env->is_really_required) {
                        return false;
                    }
                }

                return true;
            }
        );
    }
}
