<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $release_tag
 * @property Carbon $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserChangelogRead newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserChangelogRead newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserChangelogRead query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserChangelogRead whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserChangelogRead whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserChangelogRead whereReadAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserChangelogRead whereReleaseTag($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserChangelogRead whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserChangelogRead whereUserId($value)
 *
 * @mixin \Eloquent
 */
class UserChangelogRead extends Model
{
    protected $fillable = [
        'user_id',
        'release_tag',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function markAsRead(int $userId, string $identifier): void
    {
        self::firstOrCreate([
            'user_id' => $userId,
            'release_tag' => $identifier,
        ], [
            'read_at' => now(),
        ]);
    }

    public static function isReadByUser(int $userId, string $identifier): bool
    {
        return self::where('user_id', $userId)
            ->where('release_tag', $identifier)
            ->exists();
    }

    public static function getReadIdentifiersForUser(int $userId): array
    {
        return self::where('user_id', $userId)
            ->pluck('release_tag')
            ->toArray();
    }
}
