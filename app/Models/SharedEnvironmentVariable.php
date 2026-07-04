<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property string $value
 * @property bool $is_shown_once
 * @property string $type
 * @property int $team_id
 * @property int|null $project_id
 * @property int|null $environment_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property bool $is_multiline
 * @property string $version
 * @property bool $is_literal
 * @property string|null $comment
 * @property int|null $server_id
 * @property-read Environment|null $environment
 * @property-read Project|null $project
 * @property-read Server|null $server
 * @property-read Team $team
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SharedEnvironmentVariable newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SharedEnvironmentVariable newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SharedEnvironmentVariable query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SharedEnvironmentVariable whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SharedEnvironmentVariable whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SharedEnvironmentVariable whereEnvironmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SharedEnvironmentVariable whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SharedEnvironmentVariable whereIsLiteral($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SharedEnvironmentVariable whereIsMultiline($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SharedEnvironmentVariable whereIsShownOnce($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SharedEnvironmentVariable whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SharedEnvironmentVariable whereProjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SharedEnvironmentVariable whereServerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SharedEnvironmentVariable whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SharedEnvironmentVariable whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SharedEnvironmentVariable whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SharedEnvironmentVariable whereValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SharedEnvironmentVariable whereVersion($value)
 *
 * @mixin \Eloquent
 */
class SharedEnvironmentVariable extends Model
{
    protected $fillable = [
        // Core identification
        'key',
        'value',
        'comment',

        // Type and relationships
        'type',
        'team_id',
        'project_id',
        'environment_id',
        'server_id',

        // Boolean flags
        'is_multiline',
        'is_literal',
        'is_shown_once',

        // Metadata
        'version',
    ];

    protected $casts = [
        'key' => 'string',
        'value' => 'encrypted',
    ];

    protected function key(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => ValidationPatterns::validatedEnvironmentVariableKey($value),
        );
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
