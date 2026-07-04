<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project|null $project
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProjectSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProjectSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProjectSetting query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProjectSetting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProjectSetting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProjectSetting whereProjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProjectSetting whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ProjectSetting extends Model
{
    protected $fillable = [
        'project_id',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
