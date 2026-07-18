<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\DatabaseEngineRegistry;
use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;
use Visus\Cuid2\Cuid2;

/**
 * @property-read Team $team
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property int $team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Application> $applications
 * @property-read int|null $applications_count
 * @property-read Collection<int, StandaloneClickhouse> $clickhouses
 * @property-read int|null $clickhouses_count
 * @property-read Collection<int, StandaloneDragonfly> $dragonflies
 * @property-read int|null $dragonflies_count
 * @property-read Collection<int, SharedEnvironmentVariable> $environment_variables
 * @property-read int|null $environment_variables_count
 * @property-read Collection<int, Environment> $environments
 * @property-read int|null $environments_count
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
 * @property-read ProjectSetting|null $settings
 *
 * @method static \Database\Factories\ProjectFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project whereUuid($value)
 *
 * @mixin \Eloquent
 */
#[OA\Schema(
    description: 'Project model',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'uuid', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
    ]
)]
class Project extends BaseModel
{
    use ClearsGlobalSearchCache;
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasFactory;
    use HasSafeStringAttribute;

    protected $fillable = [
        'name',
        'description',
        'team_id',
        'uuid',
    ];

    /**
     * Get query builder for projects owned by current team.
     * If you need all projects without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    /**
     * @return Builder<Project>
     */
    public static function ownedByCurrentTeam(): Builder
    {
        return Project::whereTeamId(currentTeam()->id)->orderByRaw('LOWER(name)');
    }

    /**
     * Get all projects owned by current team (cached for request duration).
     *
     * @return Collection<int, Project>
     */
    public static function ownedByCurrentTeamCached(): Collection
    {
        return once(function () {
            return Project::ownedByCurrentTeam()->get();
        });
    }

    protected static function booted()
    {
        static::created(function ($project) {
            ProjectSetting::create([
                'project_id' => $project->id,
            ]);
            Environment::create([
                'name' => 'production',
                'project_id' => $project->id,
                'uuid' => (string) new Cuid2,
            ]);
        });
        static::deleting(function ($project) {
            $project->environments()->delete();
            $project->settings()->delete();
            $shared_variables = $project->environment_variables();
            foreach ($shared_variables as $shared_variable) {
                $shared_variable->delete();
            }
        });
    }

    /**
     * @return HasMany<SharedEnvironmentVariable, $this>
     */
    public function environment_variables(): HasMany
    {
        return $this->hasMany(SharedEnvironmentVariable::class)->where('type', 'project');
    }

    /**
     * @return HasMany<Environment, $this>
     */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    /**
     * @return HasOne<ProjectSetting, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(ProjectSetting::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasManyThrough<Service, Environment, $this>
     */
    public function services(): HasManyThrough
    {
        return $this->hasManyThrough(Service::class, Environment::class);
    }

    /**
     * @return HasManyThrough<Application, Environment, $this>
     */
    public function applications(): HasManyThrough
    {
        return $this->hasManyThrough(Application::class, Environment::class);
    }

    /**
     * @return HasManyThrough<StandalonePostgresql, Environment, $this>
     */
    public function postgresqls(): HasManyThrough
    {
        return $this->hasManyThrough(StandalonePostgresql::class, Environment::class);
    }

    /**
     * @return HasManyThrough<StandaloneRedis, Environment, $this>
     */
    public function redis(): HasManyThrough
    {
        return $this->hasManyThrough(StandaloneRedis::class, Environment::class);
    }

    /**
     * @return HasManyThrough<StandaloneKeydb, Environment, $this>
     */
    public function keydbs(): HasManyThrough
    {
        return $this->hasManyThrough(StandaloneKeydb::class, Environment::class);
    }

    /**
     * @return HasManyThrough<StandaloneDragonfly, Environment, $this>
     */
    public function dragonflies(): HasManyThrough
    {
        return $this->hasManyThrough(StandaloneDragonfly::class, Environment::class);
    }

    /**
     * @return HasManyThrough<StandaloneClickhouse, Environment, $this>
     */
    public function clickhouses(): HasManyThrough
    {
        return $this->hasManyThrough(StandaloneClickhouse::class, Environment::class);
    }

    /**
     * @return HasManyThrough<StandaloneMongodb, Environment, $this>
     */
    public function mongodbs(): HasManyThrough
    {
        return $this->hasManyThrough(StandaloneMongodb::class, Environment::class);
    }

    /**
     * @return HasManyThrough<StandaloneMysql, Environment, $this>
     */
    public function mysqls(): HasManyThrough
    {
        return $this->hasManyThrough(StandaloneMysql::class, Environment::class);
    }

    /**
     * @return HasManyThrough<StandaloneMariadb, Environment, $this>
     */
    public function mariadbs(): HasManyThrough
    {
        return $this->hasManyThrough(StandaloneMariadb::class, Environment::class);
    }

    public function isEmpty(): bool
    {
        if ($this->applications()->count() > 0 || $this->services()->count() > 0) {
            return false;
        }

        foreach (DatabaseEngineRegistry::relationNames() as $relationName) {
            if ($this->{$relationName}()->count() > 0) {
                return false;
            }
        }

        return true;
    }

    /** @return Collection<int, StandaloneDatabaseInstance> */
    public function databases(): Collection
    {
        $result = new Collection;
        foreach (DatabaseEngineRegistry::relationNames() as $relationName) {
            $result = $result->concat($this->{$relationName}()->get());
        }

        return $result;
    }

    public function navigateTo(): string
    {
        if ($this->environments->count() === 1) {
            return route('project.resource.index', [
                'project_uuid' => $this->uuid,
                'environment_uuid' => $this->environments->first()->uuid,
            ]);
        }

        return route('project.show', ['project_uuid' => $this->uuid]);
    }
}
