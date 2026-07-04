<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $application_id
 * @property int $server_id
 * @property int $standalone_docker_id
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionalDestinationPivot newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionalDestinationPivot newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionalDestinationPivot query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionalDestinationPivot whereApplicationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionalDestinationPivot whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionalDestinationPivot whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionalDestinationPivot whereServerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionalDestinationPivot whereStandaloneDockerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionalDestinationPivot whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionalDestinationPivot whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class AdditionalDestinationPivot extends Pivot
{
    protected $table = 'additional_destinations';
}
