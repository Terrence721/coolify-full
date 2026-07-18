<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $script
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudInitScript newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudInitScript newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudInitScript query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudInitScript whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudInitScript whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudInitScript whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudInitScript whereScript($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudInitScript whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudInitScript whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class CloudInitScript extends Model
{
    protected $fillable = [
        'team_id',
        'name',
        'script',
    ];

    protected function casts(): array
    {
        return [
            'script' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @param  array<int, string>  $select
     * @return Builder<self>
     */
    public static function ownedByCurrentTeam(array $select = ['*']): Builder
    {
        $selectArray = collect($select)->concat(['id']);

        return self::whereTeamId(currentTeam()->id)->select($selectArray->all());
    }
}
