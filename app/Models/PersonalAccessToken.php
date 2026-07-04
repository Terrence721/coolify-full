<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * @property int $id
 * @property string $tokenable_type
 * @property int $tokenable_id
 * @property string $name
 * @property string $token
 * @property string $team_id
 * @property array<array-key, mixed>|null $abilities
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $api_token_expiration_warning_sent_at
 * @property-read Model|\Eloquent $tokenable
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalAccessToken newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalAccessToken newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalAccessToken query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalAccessToken whereAbilities($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalAccessToken whereApiTokenExpirationWarningSentAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalAccessToken whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalAccessToken whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalAccessToken whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalAccessToken whereLastUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalAccessToken whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalAccessToken whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalAccessToken whereToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalAccessToken whereTokenableId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalAccessToken whereTokenableType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PersonalAccessToken whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'api_token_expiration_warning_sent_at',
        'team_id',
    ];

    protected function casts(): array
    {
        return [
            'api_token_expiration_warning_sent_at' => 'datetime',
        ];
    }
}
