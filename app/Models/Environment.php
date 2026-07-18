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
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

/**
 * @property-read Project $project
 * @property int $id
 * @property string $name
 * @property int $project_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $description
 * @property string $uuid
 * @property-read Collection<int, Application> $applications
 * @property-read int|null $applications_count
 * @property-read Collection<int, StandaloneClickhouse> $clickhouses
 * @property-read int|null $clickhouses_count
 * @property-read Collection<int, StandaloneDragonfly> $dragonflies
 * @property-read int|null $dragonflies_count
 * @property-read Collection<int, SharedEnvironmentVariable> $environment_variables
 * @property-read int|null $environment_variables_count
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
 * @method static \Database\Factories\EnvironmentFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Environment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Environment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Environment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Environment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Environment whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Environment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Environment whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Environment whereProjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Environment whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Environment whereUuid($value)
 *
 * @mixin \Eloquent
 */
#[OA\Schema(
    description: 'Environment model',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'project_id', type: 'integer'),
        new OA\Property(property: 'created_at', type: 'string'),
        new OA\Property(property: 'updated_at', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
    ]
)]
class Environment extends BaseModel
{
    use ClearsGlobalSearchCache;
    /** @use HasFactory<\Database\Factories\EnvironmentFactory> */
    use HasFactory;
    use HasSafeStringAttribute;

    protected $fillable = [
        'name',
        'description',
        'project_id',
        'uuid',
    ];

    protected static function booted()
    {
        static::deleting(function ($environment) {
            $shared_variables = $environment->environment_variables();
            foreach ($shared_variables as $shared_variable) {
                $shared_variable->delete();
            }
        });
    }

    /**
     * @return Builder<Environment>
     */
    public static function ownedByCurrentTeam(): Builder
    {
        return Environment::whereRelation('project.team', 'id', currentTeam()->id)->orderBy('name');
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

    /**
     * @return HasMany<SharedEnvironmentVariable, $this>
     */
    public function environment_variables(): HasMany
    {
        return $this->hasMany(SharedEnvironmentVariable::class)->where('type', 'environment');
    }

    /**
     * @return HasMany<Application, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * @return HasMany<StandalonePostgresql, $this>
     */
    public function postgresqls(): HasMany
    {
        return $this->hasMany(StandalonePostgresql::class);
    }

    /**
     * @return HasMany<StandaloneRedis, $this>
     */
    public function redis(): HasMany
    {
        return $this->hasMany(StandaloneRedis::class);
    }

    /**
     * @return HasMany<StandaloneMongodb, $this>
     */
    public function mongodbs(): HasMany
    {
        return $this->hasMany(StandaloneMongodb::class);
    }

    /**
     * @return HasMany<StandaloneMysql, $this>
     */
    public function mysqls(): HasMany
    {
        return $this->hasMany(StandaloneMysql::class);
    }

    /**
     * @return HasMany<StandaloneMariadb, $this>
     */
    public function mariadbs(): HasMany
    {
        return $this->hasMany(StandaloneMariadb::class);
    }

    /**
     * @return HasMany<StandaloneKeydb, $this>
     */
    public function keydbs(): HasMany
    {
        return $this->hasMany(StandaloneKeydb::class);
    }

    /**
     * @return HasMany<StandaloneDragonfly, $this>
     */
    public function dragonflies(): HasMany
    {
        return $this->hasMany(StandaloneDragonfly::class);
    }

    /**
     * @return HasMany<StandaloneClickhouse, $this>
     */
    public function clickhouses(): HasMany
    {
        return $this->hasMany(StandaloneClickhouse::class);
    }

    /** @return Collection<int, StandaloneDatabaseInstance> */
    public function databases(): Collection
    {
        $result = new Collection;
        foreach (DatabaseEngineRegistry::relationNames() as $relationName) {
            $result = $result->concat($this->{$relationName});
        }

        return $result;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    protected function customizeName(string $value): string
    {
        return str($value)->lower()->trim()->replace('/', '-')->toString();
    }
}
