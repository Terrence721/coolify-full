<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $status
 * @property string|null $message
 * @property string|null $cleanup_log
 * @property int $server_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $finished_at
 * @property-read mixed $image
 * @property-read mixed $sanitized_name
 * @property-read Server|null $server
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DockerCleanupExecution newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DockerCleanupExecution newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DockerCleanupExecution query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DockerCleanupExecution whereCleanupLog($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DockerCleanupExecution whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DockerCleanupExecution whereFinishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DockerCleanupExecution whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DockerCleanupExecution whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DockerCleanupExecution whereServerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DockerCleanupExecution whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DockerCleanupExecution whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DockerCleanupExecution whereUuid($value)
 *
 * @mixin \Eloquent
 */
class DockerCleanupExecution extends BaseModel
{
    protected $fillable = [
        'server_id',
        'status',
        'message',
        'cleanup_log',
        'finished_at',
    ];

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
