<?php

declare(strict_types=1);

namespace App\Models;

use App\Jobs\ConnectProxyToNetworksJob;
use App\Support\DatabaseEngineRegistry;
use App\Support\ValidationPatterns;
use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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

    public function applications()
    {
        return $this->morphMany(Application::class, 'destination');
    }

    public function postgresqls()
    {
        return $this->morphMany(StandalonePostgresql::class, 'destination');
    }

    public function redis()
    {
        return $this->morphMany(StandaloneRedis::class, 'destination');
    }

    public function mongodbs()
    {
        return $this->morphMany(StandaloneMongodb::class, 'destination');
    }

    public function mysqls()
    {
        return $this->morphMany(StandaloneMysql::class, 'destination');
    }

    public function mariadbs()
    {
        return $this->morphMany(StandaloneMariadb::class, 'destination');
    }

    public function keydbs()
    {
        return $this->morphMany(StandaloneKeydb::class, 'destination');
    }

    public function dragonflies()
    {
        return $this->morphMany(StandaloneDragonfly::class, 'destination');
    }

    public function clickhouses()
    {
        return $this->morphMany(StandaloneClickhouse::class, 'destination');
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public static function ownedByCurrentTeam()
    {
        $team = currentTeam();

        if (! $team) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::whereHas('server', fn ($q) => $q->whereTeamId($team->id));
    }

    public static function ownedByCurrentTeamAPI(int $teamId)
    {
        return static::whereHas('server', fn ($q) => $q->whereTeamId($teamId));
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

    public function services()
    {
        return $this->morphMany(Service::class, 'destination');
    }

    public function databases(): Collection
    {
        $result = new Collection;
        foreach (DatabaseEngineRegistry::relationNames() as $relationName) {
            $result = $result->concat($this->{$relationName});
        }

        return $result;
    }

    public function attachedTo()
    {
        return $this->applications->count() > 0 || $this->databases()->count() > 0;
    }
}
