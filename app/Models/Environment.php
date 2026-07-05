<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public static function ownedByCurrentTeam()
    {
        return Environment::whereRelation('project.team', 'id', currentTeam()->id)->orderBy('name');
    }

    public function isEmpty()
    {
        return $this->applications()->count() == 0 &&
            $this->redis()->count() == 0 &&
            $this->postgresqls()->count() == 0 &&
            $this->mysqls()->count() == 0 &&
            $this->keydbs()->count() == 0 &&
            $this->dragonflies()->count() == 0 &&
            $this->clickhouses()->count() == 0 &&
            $this->mariadbs()->count() == 0 &&
            $this->mongodbs()->count() == 0 &&
            $this->services()->count() == 0;
    }

    public function environment_variables()
    {
        return $this->hasMany(SharedEnvironmentVariable::class)->where('type', 'environment');
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    public function postgresqls()
    {
        return $this->hasMany(StandalonePostgresql::class);
    }

    public function redis()
    {
        return $this->hasMany(StandaloneRedis::class);
    }

    public function mongodbs()
    {
        return $this->hasMany(StandaloneMongodb::class);
    }

    public function mysqls()
    {
        return $this->hasMany(StandaloneMysql::class);
    }

    public function mariadbs()
    {
        return $this->hasMany(StandaloneMariadb::class);
    }

    public function keydbs()
    {
        return $this->hasMany(StandaloneKeydb::class);
    }

    public function dragonflies()
    {
        return $this->hasMany(StandaloneDragonfly::class);
    }

    public function clickhouses()
    {
        return $this->hasMany(StandaloneClickhouse::class);
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

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    protected function customizeName($value)
    {
        return str($value)->lower()->trim()->replace('/', '-')->toString();
    }
}
