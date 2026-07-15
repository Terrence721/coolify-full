<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * @property-read Service $service
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $human_name
 * @property string|null $description
 * @property string|null $fqdn
 * @property string|null $ports
 * @property string|null $exposes
 * @property string $status
 * @property int $service_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property bool $exclude_from_status
 * @property bool $required_fqdn
 * @property string|null $image
 * @property bool $is_log_drain_enabled
 * @property bool $is_include_timestamps
 * @property Carbon|null $deleted_at
 * @property bool $is_gzip_enabled
 * @property bool $is_stripprefix_enabled
 * @property string $last_online_at
 * @property bool $is_migrated
 * @property-read Collection<int, EnvironmentVariable> $environment_variables
 * @property-read int|null $environment_variables_count
 * @property-read Collection<int, LocalFileVolume> $fileStorages
 * @property-read int|null $file_storages_count
 * @property-read mixed $fqdns
 * @property-read Collection<int, LocalPersistentVolume> $persistentStorages
 * @property-read int|null $persistent_storages_count
 * @property-read mixed $sanitized_name
 *
 * @method static Builder<static>|ServiceApplication newModelQuery()
 * @method static Builder<static>|ServiceApplication newQuery()
 * @method static Builder<static>|ServiceApplication onlyTrashed()
 * @method static Builder<static>|ServiceApplication query()
 * @method static Builder<static>|ServiceApplication whereCreatedAt($value)
 * @method static Builder<static>|ServiceApplication whereDeletedAt($value)
 * @method static Builder<static>|ServiceApplication whereDescription($value)
 * @method static Builder<static>|ServiceApplication whereExcludeFromStatus($value)
 * @method static Builder<static>|ServiceApplication whereExposes($value)
 * @method static Builder<static>|ServiceApplication whereFqdn($value)
 * @method static Builder<static>|ServiceApplication whereHumanName($value)
 * @method static Builder<static>|ServiceApplication whereId($value)
 * @method static Builder<static>|ServiceApplication whereImage($value)
 * @method static Builder<static>|ServiceApplication whereIsGzipEnabled($value)
 * @method static Builder<static>|ServiceApplication whereIsIncludeTimestamps($value)
 * @method static Builder<static>|ServiceApplication whereIsLogDrainEnabled($value)
 * @method static Builder<static>|ServiceApplication whereIsMigrated($value)
 * @method static Builder<static>|ServiceApplication whereIsStripprefixEnabled($value)
 * @method static Builder<static>|ServiceApplication whereLastOnlineAt($value)
 * @method static Builder<static>|ServiceApplication whereName($value)
 * @method static Builder<static>|ServiceApplication wherePorts($value)
 * @method static Builder<static>|ServiceApplication whereRequiredFqdn($value)
 * @method static Builder<static>|ServiceApplication whereServiceId($value)
 * @method static Builder<static>|ServiceApplication whereStatus($value)
 * @method static Builder<static>|ServiceApplication whereUpdatedAt($value)
 * @method static Builder<static>|ServiceApplication whereUuid($value)
 * @method static Builder<static>|ServiceApplication withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|ServiceApplication withoutTrashed()
 *
 * @mixin \Eloquent
 */
class ServiceApplication extends BaseModel
{
    use HasFactory, SoftDeletes;

    /**
     * Both flags feed fqdnLabelsForTraefik()'s ?bool parameters via serviceParser() —
     * without the casts, SQLite (the test database) returns raw ints that fatal under
     * declare(strict_types=1); PostgreSQL happened to return real booleans, masking it.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_gzip_enabled' => 'boolean',
            'is_stripprefix_enabled' => 'boolean',
        ];
    }

    protected $fillable = [
        'service_id',
        'name',
        'human_name',
        'description',
        'fqdn',
        'ports',
        'exposes',
        'status',
        'exclude_from_status',
        'required_fqdn',
        'image',
        'is_log_drain_enabled',
        'is_include_timestamps',
        'is_gzip_enabled',
        'is_stripprefix_enabled',
        'last_online_at',
        'is_migrated',
    ];

    protected static function booted()
    {
        static::deleting(function ($service) {
            $service->update(['fqdn' => null]);
            $service->persistentStorages()->delete();
            $service->fileStorages()->delete();
        });
        static::saving(function ($service) {
            if ($service->isDirty('status')) {
                $service->last_online_at = now();
            }
        });
    }

    public function restart()
    {
        $container_id = $this->name.'-'.$this->service->uuid;
        instant_remote_process(["docker restart {$container_id}"], $this->service->server);
    }

    /**
     * @return Builder<self>
     */
    public static function ownedByCurrentTeamAPI(int $teamId): Builder
    {
        return ServiceApplication::whereRelation('service.environment.project.team', 'id', $teamId)->orderBy('name');
    }

    /**
     * Get query builder for service applications owned by current team.
     * If you need all service applications without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    /**
     * @return Builder<self>
     */
    public static function ownedByCurrentTeam(): Builder
    {
        $team = currentTeam();

        if (! $team) {
            return ServiceApplication::query()->whereRaw('1 = 0')->orderBy('name');
        }

        return ServiceApplication::whereRelation('service.environment.project.team', 'id', $team->id)->orderBy('name');
    }

    /**
     * Get all service applications owned by current team (cached for request duration).
     */
    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return ServiceApplication::ownedByCurrentTeam()->get();
        });
    }

    public function isRunning()
    {
        return str($this->status)->contains('running');
    }

    public function isExited()
    {
        return str($this->status)->contains('exited');
    }

    public function isLogDrainEnabled()
    {
        return data_get($this, 'is_log_drain_enabled', false);
    }

    public function isStripprefixEnabled()
    {
        return data_get($this, 'is_stripprefix_enabled', true);
    }

    public function isGzipEnabled()
    {
        return data_get($this, 'is_gzip_enabled', true);
    }

    public function type()
    {
        return 'service';
    }

    public function team()
    {
        return data_get($this, 'service.environment.project.team');
    }

    public function workdir()
    {
        return service_configuration_dir()."/{$this->service->uuid}";
    }

    public function serviceType()
    {
        $found = str(collect(SPECIFIC_SERVICES)->filter(function ($service) {
            return str($this->image)->before(':')->value() === $service;
        })->first());
        if ($found->isNotEmpty()) {
            return $found;
        }

        return null;
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return MorphMany<LocalPersistentVolume, $this>
     */
    public function persistentStorages(): MorphMany
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }

    /**
     * @return MorphMany<LocalFileVolume, $this>
     */
    public function fileStorages(): MorphMany
    {
        return $this->morphMany(LocalFileVolume::class, 'resource');
    }

    /**
     * @return MorphMany<EnvironmentVariable, $this>
     */
    public function environment_variables(): MorphMany
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable');
    }

    public function fqdns(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->fqdn)
                ? []
                : explode(',', $this->fqdn),
        );
    }

    /**
     * Extract port number from a given FQDN URL.
     * Returns null if no port is specified.
     */
    public static function extractPortFromUrl(string $url): ?int
    {
        try {
            // Ensure URL has a scheme for proper parsing
            if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                $url = 'http://'.$url;
            }

            $parsed = parse_url($url);
            $port = $parsed['port'] ?? null;

            return $port ? (int) $port : null;
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in extractPortFromUrl().', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Check if all FQDNs have a port specified.
     */
    public function allFqdnsHavePort(): bool
    {
        if (is_null($this->fqdn) || $this->fqdn === '') {
            return false;
        }

        $fqdns = explode(',', $this->fqdn);

        foreach ($fqdns as $fqdn) {
            $fqdn = trim($fqdn);
            if (empty($fqdn)) {
                continue;
            }

            $port = self::extractPortFromUrl($fqdn);
            if ($port === null) {
                return false;
            }
        }

        return true;
    }

    public function getFilesFromServer(bool $isInit = false)
    {
        getFilesystemVolumesFromServer($this, $isInit);
    }

    public function isBackupSolutionAvailable()
    {
        return false;
    }

    /**
     * Get the required port for this service application.
     * Extracts port from SERVICE_URL_* or SERVICE_FQDN_* environment variables
     * stored at the Service level, filtering by normalized container name.
     * Falls back to service-level port if no port-specific variable is found.
     */
    public function getRequiredPort(): ?int
    {
        try {
            // Parse the Docker Compose to find SERVICE_URL/SERVICE_FQDN variables DIRECTLY DECLARED
            // for this specific service container (not just referenced from other containers)
            $dockerComposeRaw = data_get($this->service, 'docker_compose_raw');
            if (! $dockerComposeRaw) {
                // Fall back to service-level port if no compose file
                return $this->service->getRequiredPort();
            }

            $dockerCompose = Yaml::parse($dockerComposeRaw);
            $serviceConfig = data_get($dockerCompose, "services.{$this->name}");
            if (! $serviceConfig) {
                return $this->service->getRequiredPort();
            }

            $environment = data_get($serviceConfig, 'environment', []);

            // Extract SERVICE_URL and SERVICE_FQDN variables DIRECTLY DECLARED in this service's environment
            // (not variables that are merely referenced with ${VAR} syntax)
            $portFound = null;
            foreach ($environment as $key => $value) {
                if (is_int($key) && is_string($value)) {
                    // List-style: "- SERVICE_URL_APP_3000" or "- SERVICE_URL_APP_3000=value"
                    // Extract variable name (before '=' if present)
                    $envVarName = str($value)->before('=')->trim();

                    // Only process direct declarations
                    if ($envVarName->startsWith('SERVICE_FQDN_') || $envVarName->startsWith('SERVICE_URL_')) {
                        // Parse to check if it has a port suffix
                        $parsed = parseServiceEnvironmentVariable($envVarName->value());
                        if ($parsed['has_port'] && $parsed['port']) {
                            // Found a port-specific variable for this service
                            $portFound = (int) $parsed['port'];
                            break;
                        }
                    }
                } elseif (is_string($key)) {
                    // Map-style: "SERVICE_URL_APP_3000: value" or "SERVICE_FQDN_DB: localhost"
                    $envVarName = str($key);

                    // Only process direct declarations
                    if ($envVarName->startsWith('SERVICE_FQDN_') || $envVarName->startsWith('SERVICE_URL_')) {
                        // Parse to check if it has a port suffix
                        $parsed = parseServiceEnvironmentVariable($envVarName->value());
                        if ($parsed['has_port'] && $parsed['port']) {
                            // Found a port-specific variable for this service
                            $portFound = (int) $parsed['port'];
                            break;
                        }
                    }
                }
            }

            // If a port was found in the template, return it
            if ($portFound !== null) {
                return $portFound;
            }

            // No port-specific variables found for this service, return null
            // (DO NOT fall back to service-level port, as that applies to all services)
            return null;
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in getRequiredPort().', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
