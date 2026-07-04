<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property-read Team|null $team
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property string $email
 * @property string $role
 * @property string $link
 * @property string $via
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|TeamInvitation newModelQuery()
 * @method static Builder<static>|TeamInvitation newQuery()
 * @method static Builder<static>|TeamInvitation query()
 * @method static Builder<static>|TeamInvitation whereCreatedAt($value)
 * @method static Builder<static>|TeamInvitation whereEmail($value)
 * @method static Builder<static>|TeamInvitation whereId($value)
 * @method static Builder<static>|TeamInvitation whereLink($value)
 * @method static Builder<static>|TeamInvitation whereRole($value)
 * @method static Builder<static>|TeamInvitation whereTeamId($value)
 * @method static Builder<static>|TeamInvitation whereUpdatedAt($value)
 * @method static Builder<static>|TeamInvitation whereUuid($value)
 * @method static Builder<static>|TeamInvitation whereVia($value)
 *
 * @mixin \Eloquent
 */
class TeamInvitation extends Model
{
    protected $fillable = [
        'team_id',
        'uuid',
        'email',
        'role',
        'link',
        'via',
    ];

    /**
     * Set the email attribute to lowercase.
     */
    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower($value);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return Builder<self>
     */
    public static function ownedByCurrentTeam(): Builder
    {
        return TeamInvitation::whereTeamId(currentTeam()->id);
    }

    public function isValid()
    {
        $createdAt = $this->created_at;
        $diff = $createdAt->diffInDays(now());
        if ($diff <= config('constants.invitation.link.expiration_days')) {
            return true;
        } else {
            $this->delete();
            $user = User::whereEmail($this->email)->first();
            if (filled($user)) {
                $user->deleteIfNotVerifiedAndForcePasswordReset();
            }

            return false;
        }
    }
}
