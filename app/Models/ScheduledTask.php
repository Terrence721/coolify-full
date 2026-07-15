<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

/**
 * @property int $id
 * @property string $uuid
 * @property bool $enabled
 * @property string $name
 * @property string $command
 * @property string $frequency
 * @property string|null $container
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $application_id
 * @property int|null $service_id
 * @property int $team_id
 * @property int $timeout
 * @property-read Application|null $application
 * @property-read Collection<int, ScheduledTaskExecution> $executions
 * @property-read int|null $executions_count
 * @property-read mixed $image
 * @property-read ScheduledTaskExecution|null $latest_log
 * @property-read mixed $sanitized_name
 * @property-read Service|null $service
 * @property-write mixed $description
 *
 * @method static \Database\Factories\ScheduledTaskFactory factory($count = null, $state = [])
 * @method static Builder<static>|ScheduledTask newModelQuery()
 * @method static Builder<static>|ScheduledTask newQuery()
 * @method static Builder<static>|ScheduledTask query()
 * @method static Builder<static>|ScheduledTask whereApplicationId($value)
 * @method static Builder<static>|ScheduledTask whereCommand($value)
 * @method static Builder<static>|ScheduledTask whereContainer($value)
 * @method static Builder<static>|ScheduledTask whereCreatedAt($value)
 * @method static Builder<static>|ScheduledTask whereEnabled($value)
 * @method static Builder<static>|ScheduledTask whereFrequency($value)
 * @method static Builder<static>|ScheduledTask whereId($value)
 * @method static Builder<static>|ScheduledTask whereName($value)
 * @method static Builder<static>|ScheduledTask whereServiceId($value)
 * @method static Builder<static>|ScheduledTask whereTeamId($value)
 * @method static Builder<static>|ScheduledTask whereTimeout($value)
 * @method static Builder<static>|ScheduledTask whereUpdatedAt($value)
 * @method static Builder<static>|ScheduledTask whereUuid($value)
 *
 * @mixin \Eloquent
 */
#[OA\Schema(
    description: 'Scheduled Task model',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', description: 'The unique identifier of the scheduled task in the database.'),
        new OA\Property(property: 'uuid', type: 'string', description: 'The unique identifier of the scheduled task.'),
        new OA\Property(property: 'enabled', type: 'boolean', description: 'The flag to indicate if the scheduled task is enabled.'),
        new OA\Property(property: 'name', type: 'string', description: 'The name of the scheduled task.'),
        new OA\Property(property: 'command', type: 'string', description: 'The command to execute.'),
        new OA\Property(property: 'frequency', type: 'string', description: 'The frequency of the scheduled task.'),
        new OA\Property(property: 'container', type: 'string', nullable: true, description: 'The container where the command should be executed.'),
        new OA\Property(property: 'timeout', type: 'integer', description: 'The timeout of the scheduled task in seconds.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', description: 'The date and time when the scheduled task was created.'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', description: 'The date and time when the scheduled task was last updated.'),
    ],
)]
class ScheduledTask extends BaseModel
{
    use HasFactory;
    use HasSafeStringAttribute;

    protected $fillable = [
        'uuid',
        'enabled',
        'name',
        'command',
        'frequency',
        'container',
        'timeout',
        'team_id',
        'application_id',
        'service_id',
    ];

    /**
     * @return Builder<self>
     */
    public static function ownedByCurrentTeamAPI(int|string $teamId): Builder
    {
        return static::where('team_id', $teamId)->orderBy('created_at', 'desc');
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'timeout' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return HasOne<ScheduledTaskExecution, $this>
     */
    public function latest_log(): HasOne
    {
        return $this->hasOne(ScheduledTaskExecution::class)->latest();
    }

    /**
     * @return HasMany<ScheduledTaskExecution, $this>
     */
    public function executions(): HasMany
    {
        // Last execution first
        return $this->hasMany(ScheduledTaskExecution::class)->orderBy('created_at', 'desc');
    }

    public function server(): ?Server
    {
        if ($this->application) {
            return $this->application->destination?->server;
        }

        if ($this->service) {
            return $this->service->destination?->server;
        }

        return null;
    }
}
