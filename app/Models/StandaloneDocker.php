<?php

declare(strict_types=1);

namespace App\Models;

use App\Jobs\ConnectProxyToNetworksJob;
use App\Support\DatabaseEngineRegistry;
use App\Support\ValidationPatterns;
use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $server_id
 * @property string $network
 * @property-read Server|null $server
 * @property int $id
 * @property string $name
 * @property string $uuid
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Application> $applications
 * @property-read int|null $applications_count
 * @property-read Collection<int, StandaloneClickhouse> $clickhouses
 * @property-read int|null $clickhouses_count
 * @property-read Collection<int, StandaloneDragonfly> $dragonflies
 * @property-read int|null $dragonflies_count
 * @property-read mixed $image
 * @property-read Collection<int, StandaloneKeydb> $keydbs
 * @property-read int|null $keydbs_count
 * @property-read Collection<int, StandaloneMariadb> $mariadbs
 * @property-read int|null $mariadbs_count
 * @property-read Collection<int, StandaloneMongodb> $mongodbs
 * @property-read int|null $mongodbs_count
 * @property-read Collection<int, StandaloneMysql> $mysqls
 * @property-read int|null $mysqls_count
 * @property-read Collection<int, StandalonePostgresql> $postgresqls
 * @property-read int|null $postgresqls_count
 * @property-read Collection<int, StandaloneRedis> $redis
 * @property-read int|null $redis_count
 * @property-read mixed $sanitized_name
 * @property-read Collection<int, Service> $services
 * @property-read int|null $services_count
 * @property-write mixed $description
 *
 * @method static \Database\Factories\StandaloneDockerFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDocker newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDocker newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDocker query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDocker whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDocker whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDocker whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDocker whereNetwork($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDocker whereServerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDocker whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StandaloneDocker whereUuid($value)
 *
 * @mixin \Eloquent
 */
class StandaloneDocker extends BaseModel
{
    /** @use HasFactory<\Database\Factories\StandaloneDockerFactory> */
    use HasFactory;
    use HasSafeStringAttribute;

    protected $fillable = [
        'server_id',
        'name',
        'network',
    ];

    protected static function boot()
    {
        parent::boot();
        static::created(function ($newStandaloneDocker) {
            $server = $newStandaloneDocker->server;
            $safeNetwork = escapeshellarg($newStandaloneDocker->network);
            instant_remote_process([
                "docker network inspect {$safeNetwork} >/dev/null 2>&1 || docker network create --driver overlay --attachable {$safeNetwork} >/dev/null",
            ], $server, false);
            ConnectProxyToNetworksJob::dispatchSync($server);
        });
    }

    public function setNetworkAttribute(string $value): void
    {
        if (! ValidationPatterns::isValidDockerNetwork($value)) {
            throw new \InvalidArgumentException('Invalid Docker network name. Must start with alphanumeric and contain only alphanumeric characters, dots, hyphens, and underscores.');
        }

        $this->attributes['network'] = $value;
    }

    /**
     * @return MorphMany<Application, $this>
     */
    public function applications(): MorphMany
    {
        return $this->morphMany(Application::class, 'destination');
    }

    /**
     * @return MorphMany<StandalonePostgresql, $this>
     */
    public function postgresqls(): MorphMany
    {
        return $this->morphMany(StandalonePostgresql::class, 'destination');
    }

    /**
     * @return MorphMany<StandaloneRedis, $this>
     */
    public function redis(): MorphMany
    {
        return $this->morphMany(StandaloneRedis::class, 'destination');
    }

    /**
     * @return MorphMany<StandaloneMongodb, $this>
     */
    public function mongodbs(): MorphMany
    {
        return $this->morphMany(StandaloneMongodb::class, 'destination');
    }

    /**
     * @return MorphMany<StandaloneMysql, $this>
     */
    public function mysqls(): MorphMany
    {
        return $this->morphMany(StandaloneMysql::class, 'destination');
    }

    /**
     * @return MorphMany<StandaloneMariadb, $this>
     */
    public function mariadbs(): MorphMany
    {
        return $this->morphMany(StandaloneMariadb::class, 'destination');
    }

    /**
     * @return MorphMany<StandaloneKeydb, $this>
     */
    public function keydbs(): MorphMany
    {
        return $this->morphMany(StandaloneKeydb::class, 'destination');
    }

    /**
     * @return MorphMany<StandaloneDragonfly, $this>
     */
    public function dragonflies(): MorphMany
    {
        return $this->morphMany(StandaloneDragonfly::class, 'destination');
    }

    /**
     * @return MorphMany<StandaloneClickhouse, $this>
     */
    public function clickhouses(): MorphMany
    {
        return $this->morphMany(StandaloneClickhouse::class, 'destination');
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return Builder<StandaloneDocker>
     */
    public static function ownedByCurrentTeam(): Builder
    {
        $team = currentTeam();

        if (! $team) {
            return StandaloneDocker::query()->whereRaw('1 = 0');
        }

        return StandaloneDocker::whereHas('server', fn ($q) => $q->whereTeamId($team->id));
    }

    /**
     * @return Builder<StandaloneDocker>
     */
    public static function ownedByCurrentTeamAPI(int|string $teamId): Builder
    {
        return StandaloneDocker::whereHas('server', fn ($q) => $q->whereTeamId($teamId));
    }

    /**
     * Get the server attribute using identity map caching.
     * This intercepts lazy-loading to use cached Server lookups.
     */
    public function getServerAttribute(): ?Server
    {
        // Use eager loaded data if available
        if ($this->relationLoaded('server')) {
            return $this->getRelation('server');
        }

        // Use identity map for lazy loading
        $server = Server::findCached($this->server_id);

        // Cache in relation for future access on this instance
        if ($server) {
            $this->setRelation('server', $server);
        }

        return $server;
    }

    /**
     * @return MorphMany<Service, $this>
     */
    public function services(): MorphMany
    {
        return $this->morphMany(Service::class, 'destination');
    }

    /**
     * @return Collection<int, StandaloneDatabaseInstance>
     */
    public function databases(): Collection
    {
        $result = new Collection;
        foreach (DatabaseEngineRegistry::relationNames() as $relationName) {
            $result = $result->concat($this->{$relationName});
        }

        return $result;
    }

    public function attachedTo(): bool
    {
        return $this->applications->count() > 0 || $this->databases()->count() > 0;
    }
}
