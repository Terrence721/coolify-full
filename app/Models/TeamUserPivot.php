<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property string $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamUserPivot newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamUserPivot newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamUserPivot query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamUserPivot whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamUserPivot whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamUserPivot whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamUserPivot whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamUserPivot whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamUserPivot whereUserId($value)
 *
 * @mixin \Eloquent
 */
class TeamUserPivot extends Pivot
{
    protected $table = 'team_user';
}
