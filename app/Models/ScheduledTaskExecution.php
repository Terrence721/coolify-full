<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

/**
 * @property int $id
 * @property string $uuid
 * @property string $status
 * @property string|null $message
 * @property int $scheduled_task_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $started_at
 * @property int $retry_count
 * @property numeric|null $duration Duration in seconds
 * @property string|null $error_details
 * @property-read mixed $image
 * @property-read mixed $sanitized_name
 * @property-read ScheduledTask|null $scheduledTask
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledTaskExecution newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledTaskExecution newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledTaskExecution query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledTaskExecution whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledTaskExecution whereDuration($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledTaskExecution whereErrorDetails($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledTaskExecution whereFinishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledTaskExecution whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledTaskExecution whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledTaskExecution whereRetryCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledTaskExecution whereScheduledTaskId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledTaskExecution whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledTaskExecution whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledTaskExecution whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledTaskExecution whereUuid($value)
 *
 * @mixin \Eloquent
 */
#[OA\Schema(
    description: 'Scheduled Task Execution model',
    type: 'object',
    properties: [
        'uuid' => ['type' => 'string', 'description' => 'The unique identifier of the execution.'],
        'status' => ['type' => 'string', 'enum' => ['success', 'failed', 'running'], 'description' => 'The status of the execution.'],
        'message' => ['type' => 'string', 'nullable' => true, 'description' => 'The output message of the execution.'],
        'retry_count' => ['type' => 'integer', 'description' => 'The number of retries.'],
        'duration' => ['type' => 'number', 'nullable' => true, 'description' => 'Duration in seconds.'],
        'started_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'When the execution started.'],
        'finished_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'When the execution finished.'],
        'created_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'When the record was created.'],
        'updated_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'When the record was last updated.'],
    ],
)]
class ScheduledTaskExecution extends BaseModel
{
    protected $fillable = [
        'scheduled_task_id',
        'status',
        'message',
        'finished_at',
        'started_at',
        'retry_count',
        'duration',
        'error_details',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'retry_count' => 'integer',
            'duration' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<ScheduledTask, $this>
     */
    public function scheduledTask(): BelongsTo
    {
        return $this->belongsTo(ScheduledTask::class);
    }
}
