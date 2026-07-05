<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Collection;
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
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwarmDocker newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwarmDocker newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwarmDocker query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwarmDocker whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwarmDocker whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwarmDocker whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwarmDocker whereNetwork($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwarmDocker whereServerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwarmDocker whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SwarmDocker whereUuid($value)
 *
 * @mixin \Eloquent
 */
class SwarmDocker extends BaseModel
{
    protected $fillable = [
        'server_id',
        'name',
        'network',
    ];

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

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public static function ownedByCurrentTeam()
    {
        $team = currentTeam();
        if (! $team) {
            return static::query()->whereRaw('0=1');
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

    public function databases()
    {
        $postgresqls = $this->postgresqls;
        $redis = $this->redis;
        $mongodbs = $this->mongodbs;
        $mysqls = $this->mysqls;
        $mariadbs = $this->mariadbs;
        $keydbs = $this->keydbs;
        $dragonflies = $this->dragonflies;
        $clickhouses = $this->clickhouses;

        return $postgresqls->concat($redis)->concat($mongodbs)->concat($mysqls)->concat($mariadbs)->concat($keydbs)->concat($dragonflies)->concat($clickhouses);
    }

    public function attachedTo()
    {
        return $this->applications->count() > 0 || $this->databases()->count() > 0;
    }
}
