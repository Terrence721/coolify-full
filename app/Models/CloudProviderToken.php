<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $provider
 * @property string $token
 * @property string|null $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $uuid
 * @property-read mixed $image
 * @property-read mixed $sanitized_name
 * @property-read Collection<int, Server> $servers
 * @property-read int|null $servers_count
 * @property-read Team $team
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudProviderToken forProvider(string $provider)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudProviderToken newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudProviderToken newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudProviderToken query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudProviderToken whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudProviderToken whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudProviderToken whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudProviderToken whereProvider($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudProviderToken whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudProviderToken whereToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudProviderToken whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CloudProviderToken whereUuid($value)
 *
 * @mixin \Eloquent
 */
class CloudProviderToken extends BaseModel
{
    protected $fillable = [
        'team_id',
        'provider',
        'token',
        'name',
    ];

    protected $casts = [
        'token' => 'encrypted',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function servers()
    {
        return $this->hasMany(Server::class);
    }

    public function hasServers(): bool
    {
        return $this->servers()->exists();
    }

    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        $selectArray = collect($select)->concat(['id']);

        return self::whereTeamId(currentTeam()->id)->select($selectArray->all());
    }

    public function scopeForProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }
}
