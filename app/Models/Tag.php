<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property int|null $team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Application> $applications
 * @property-read int|null $applications_count
 * @property-read mixed $image
 * @property-read mixed $sanitized_name
 * @property-read Collection<int, Service> $services
 * @property-read int|null $services_count
 * @property-write mixed $description
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tag newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tag newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tag query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tag whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tag whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tag whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tag whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tag whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tag whereUuid($value)
 *
 * @mixin \Eloquent
 */
class Tag extends BaseModel
{
    use HasSafeStringAttribute;

    protected $fillable = [
        'name',
        'team_id',
    ];

    protected function customizeName(string $value): string
    {
        return strtolower($value);
    }

    /**
     * @return Builder<self>
     */
    public static function ownedByCurrentTeam(): Builder
    {
        return Tag::whereTeamId(currentTeam()->id)->orderBy('name');
    }

    /**
     * @return MorphToMany<Application, $this>
     */
    public function applications(): MorphToMany
    {
        return $this->morphedByMany(Application::class, 'taggable');
    }

    /**
     * @return MorphToMany<Service, $this>
     */
    public function services(): MorphToMany
    {
        return $this->morphedByMany(Service::class, 'taggable');
    }
}
