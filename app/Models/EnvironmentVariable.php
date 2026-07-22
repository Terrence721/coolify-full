<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\EnvironmentVariable as ModelsEnvironmentVariable;
use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

/**
 * @property string $key
 * @property string|null $value
 * @property bool $is_literal
 * @property bool $is_multiline
 * @property bool $is_required
 * @property bool $is_shared
 * @property mixed $resourceable
 * @property-read string|null $real_value
 * @property int $id
 * @property bool $is_preview
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property bool $is_shown_once
 * @property string $version
 * @property string $uuid
 * @property int|null $order
 * @property string|null $resourceable_type
 * @property int|null $resourceable_id
 * @property bool $is_runtime
 * @property bool $is_buildtime
 * @property string|null $comment
 * @property-read mixed $image
 * @property-read mixed $is_buildpack_control
 * @property-read mixed $is_coolify
 * @property-read mixed $is_really_required
 * @property-read mixed $sanitized_name
 * @property-read Service|null $service
 *
 * @method static Builder<static>|EnvironmentVariable newModelQuery()
 * @method static Builder<static>|EnvironmentVariable newQuery()
 * @method static Builder<static>|EnvironmentVariable query()
 * @method static Builder<static>|EnvironmentVariable whereComment($value)
 * @method static Builder<static>|EnvironmentVariable whereCreatedAt($value)
 * @method static Builder<static>|EnvironmentVariable whereId($value)
 * @method static Builder<static>|EnvironmentVariable whereIsBuildtime($value)
 * @method static Builder<static>|EnvironmentVariable whereIsLiteral($value)
 * @method static Builder<static>|EnvironmentVariable whereIsMultiline($value)
 * @method static Builder<static>|EnvironmentVariable whereIsPreview($value)
 * @method static Builder<static>|EnvironmentVariable whereIsRequired($value)
 * @method static Builder<static>|EnvironmentVariable whereIsRuntime($value)
 * @method static Builder<static>|EnvironmentVariable whereIsShared($value)
 * @method static Builder<static>|EnvironmentVariable whereIsShownOnce($value)
 * @method static Builder<static>|EnvironmentVariable whereKey($value)
 * @method static Builder<static>|EnvironmentVariable whereOrder($value)
 * @method static Builder<static>|EnvironmentVariable whereResourceableId($value)
 * @method static Builder<static>|EnvironmentVariable whereResourceableType($value)
 * @method static Builder<static>|EnvironmentVariable whereUpdatedAt($value)
 * @method static Builder<static>|EnvironmentVariable whereUuid($value)
 * @method static Builder<static>|EnvironmentVariable whereValue($value)
 * @method static Builder<static>|EnvironmentVariable whereVersion($value)
 * @method static Builder<static>|EnvironmentVariable withoutBuildpackControlVariables()
 *
 * @mixin \Eloquent
 */
#[OA\Schema(
    description: 'Environment Variable model',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'uuid', type: 'string'),
        new OA\Property(property: 'resourceable_type', type: 'string'),
        new OA\Property(property: 'resourceable_id', type: 'integer'),
        new OA\Property(property: 'is_literal', type: 'boolean'),
        new OA\Property(property: 'is_multiline', type: 'boolean'),
        new OA\Property(property: 'is_preview', type: 'boolean'),
        new OA\Property(property: 'is_runtime', type: 'boolean'),
        new OA\Property(property: 'is_buildtime', type: 'boolean'),
        new OA\Property(property: 'is_shared', type: 'boolean'),
        new OA\Property(property: 'is_shown_once', type: 'boolean'),
        new OA\Property(property: 'key', type: 'string'),
        new OA\Property(property: 'value', type: 'string'),
        new OA\Property(property: 'real_value', type: 'string'),
        new OA\Property(property: 'comment', type: 'string', nullable: true),
        new OA\Property(property: 'version', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string'),
        new OA\Property(property: 'updated_at', type: 'string'),
    ]
)]
class EnvironmentVariable extends BaseModel
{
    public const BUILDPACK_CONTROL_VARIABLE_PREFIXES = ['NIXPACKS_', 'RAILPACK_'];

    protected $attributes = [
        'is_runtime' => true,
        'is_buildtime' => true,
    ];

    protected $fillable = [
        // Core identification
        'key',
        'value',
        'comment',

        // Polymorphic relationship
        'resourceable_type',
        'resourceable_id',

        // Boolean flags
        'is_preview',
        'is_multiline',
        'is_literal',
        'is_runtime',
        'is_buildtime',
        'is_shown_once',
        'is_shared',
        'is_required',

        // Metadata
        'version',
        'order',
    ];

    protected $casts = [
        'key' => 'string',
        'value' => 'encrypted',
        'is_multiline' => 'boolean',
        'is_preview' => 'boolean',
        'is_runtime' => 'boolean',
        'is_buildtime' => 'boolean',
        'version' => 'string',
        'resourceable_type' => 'string',
        'resourceable_id' => 'integer',
    ];

    protected $appends = ['is_really_required', 'is_buildpack_control', 'is_coolify'];

    protected static function booted()
    {
        static::created(function (ModelsEnvironmentVariable $environment_variable) {
            if (data_get($environment_variable, 'resourceable_type') === Application::class && ! data_get($environment_variable, 'is_preview')) {
                $found = ModelsEnvironmentVariable::where('key', data_get($environment_variable, 'key'))
                    ->where('resourceable_type', Application::class)
                    ->where('resourceable_id', data_get($environment_variable, 'resourceable_id'))
                    ->where('is_preview', true)
                    ->first();

                if (! $found) {
                    $application = Application::find(data_get($environment_variable, 'resourceable_id'));
                    if ($application) {
                        ModelsEnvironmentVariable::create([
                            'key' => data_get($environment_variable, 'key'),
                            'value' => data_get($environment_variable, 'value'),
                            'is_multiline' => data_get($environment_variable, 'is_multiline', false),
                            'is_literal' => data_get($environment_variable, 'is_literal', false),
                            'is_runtime' => data_get($environment_variable, 'is_runtime', false),
                            'is_buildtime' => data_get($environment_variable, 'is_buildtime', false),
                            'comment' => data_get($environment_variable, 'comment'),
                            'resourceable_type' => Application::class,
                            'resourceable_id' => data_get($environment_variable, 'resourceable_id'),
                            'is_preview' => true,
                        ]);
                    }
                }
            }
            $environment_variable->update([
                'version' => config('constants.coolify.version'),
            ]);
        });

        static::saving(function (ModelsEnvironmentVariable $environmentVariable) {
            $environmentVariable->updateIsShared();
        });
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @param  Builder<ModelsEnvironmentVariable>  $query
     * @return Builder<ModelsEnvironmentVariable>
     */
    public function scopeWithoutBuildpackControlVariables(Builder $query): Builder
    {
        foreach (self::BUILDPACK_CONTROL_VARIABLE_PREFIXES as $prefix) {
            $query->where('key', 'not like', "{$prefix}%");
        }

        return $query;
    }

    public static function isBuildpackControlKey(?string $key): bool
    {
        if (blank($key)) {
            return false;
        }

        foreach (self::BUILDPACK_CONTROL_VARIABLE_PREFIXES as $prefix) {
            if (str($key)->startsWith($prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value = null): ?string => $this->get_environment_variables($value),
            set: fn (?string $value = null): ?string => $this->set_environment_variables($value),
        );
    }

    /**
     * Get the parent resourceable model.
     *
     * @return MorphTo<Model, $this>
     */
    public function resourceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function resource(): ?Model
    {
        return $this->resourceable()->getResults();
    }

    /**
     * @return Attribute<string|null, never>
     */
    public function realValue(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->relationLoaded('resourceable')) {
                    $this->load('resourceable');
                }
                $resource = $this->resourceable()->getResults();
                if (! $resource) {
                    return null;
                }

                // Load relationships needed for shared variable resolution
                if (! $resource->relationLoaded('environment')) {
                    $resource->load('environment');
                }
                if (! $resource->relationLoaded('server') && method_exists($resource, 'server')) {
                    $resource->load('server');
                }
                if (! $resource->relationLoaded('destination') && method_exists($resource, 'destination')) {
                    $resource->load('destination.server');
                }

                $real_value = $this->get_real_environment_variables(data_get($this, 'value'), $resource);

                if ($real_value === null) {
                    return null;
                }

                // Skip escaping for valid JSON objects/arrays to prevent quote corruption (see #6160)
                if (json_validate($real_value) && (str_starts_with($real_value, '{') || str_starts_with($real_value, '['))) {
                    return $real_value;
                }

                if ($this->is_literal || $this->is_multiline) {
                    $real_value = '\''.$real_value.'\'';
                } else {
                    $real_value = escapeEnvVariables($real_value);
                }

                return $real_value;
            }
        );
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isReallyRequired(): Attribute
    {
        return Attribute::make(
            get: fn () => (bool) data_get($this, 'is_required') && str(data_get($this, 'real_value'))->isEmpty(),
        );
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isBuildpackControl(): Attribute
    {
        return Attribute::make(
            get: fn () => self::isBuildpackControlKey(data_get($this, 'key')),
        );
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isCoolify(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (str((string) data_get($this, 'key'))->startsWith('SERVICE_')) {
                    return true;
                }

                return false;
            }
        );
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isShared(): Attribute
    {
        return Attribute::make(
            get: function () {
                $value = (string) data_get($this, 'value', '');
                $type = str($value)->after('{{')->before('.')->value();
                if (str($value)->startsWith('{{'.$type) && str($value)->endsWith('}}')) {
                    return true;
                }

                return false;
            }
        );
    }

    public function get_real_environment_variables_with_server(?string $environment_variable = null, ?Model $resource = null, ?Server $server = null): ?string
    {
        return $this->get_real_environment_variables_internal($environment_variable, $resource, $server);
    }

    public function getResolvedValueWithServer(?Server $server = null): ?string
    {
        if (! $this->relationLoaded('resourceable')) {
            $this->load('resourceable');
        }
        $resource = $this->resourceable()->getResults();
        if (! $resource) {
            return null;
        }

        // Load relationships needed for shared variable resolution
        if (! $resource->relationLoaded('environment')) {
            $resource->load('environment');
        }
        if (! $resource->relationLoaded('server') && method_exists($resource, 'server')) {
            $resource->load('server');
        }
        if (! $resource->relationLoaded('destination') && method_exists($resource, 'destination')) {
            $resource->load('destination.server');
        }

        $real_value = $this->get_real_environment_variables_internal(data_get($this, 'value'), $resource, $server);

        // Skip escaping for valid JSON objects/arrays to prevent quote corruption (see #6160)
        if (json_validate($real_value) && (str_starts_with($real_value, '{') || str_starts_with($real_value, '['))) {
            return $real_value;
        }

        if ($this->is_literal || $this->is_multiline) {
            $real_value = '\''.$real_value.'\'';
        } else {
            $real_value = escapeEnvVariables($real_value);
        }

        return $real_value;
    }

    private function get_real_environment_variables(?string $environment_variable = null, ?Model $resource = null): ?string
    {
        return $this->get_real_environment_variables_internal($environment_variable, $resource);
    }

    private function get_real_environment_variables_internal(?string $environment_variable = null, ?Model $resource = null, ?Server $serverOverride = null): ?string
    {
        if (is_null($environment_variable) || $environment_variable === '' || is_null($resource)) {
            return $environment_variable;
        }
        $environment_variable = trim($environment_variable);
        $sharedEnvsFound = str($environment_variable)->matchAll('/{{(.*?)}}/');
        if ($sharedEnvsFound->isEmpty()) {
            return $environment_variable;
        }
        foreach ($sharedEnvsFound as $sharedEnv) {
            $type = str($sharedEnv)->trim()->match('/(.*?)\./');
            if (! collect(SHARED_VARIABLE_TYPES)->contains($type)) {
                continue;
            }
            $variable = str($sharedEnv)->trim()->match('/\.(.*)/');
            $id = null;
            if ($type->value() === 'environment') {
                $id = data_get($resource, 'environment.id');
            } elseif ($type->value() === 'project') {
                $id = data_get($resource, 'environment.project.id');
            } elseif ($type->value() === 'team') {
                $id = data_get($resource, 'team.id');
            } elseif ($type->value() === 'server') {
                if ($serverOverride) {
                    $id = $serverOverride->id;
                } elseif (data_get($resource, 'server')) {
                    $id = data_get($resource, 'server.id');
                } elseif (data_get($resource, 'destination.server')) {
                    $id = data_get($resource, 'destination.server.id');
                }
            }
            if (is_null($id)) {
                continue;
            }
            $found = SharedEnvironmentVariable::where('type', $type)
                ->where('key', $variable)
                ->where('team_id', data_get($resource, 'team.id'))
                ->where("{$type}_id", $id)
                ->first();
            if ($found) {
                $environment_variable = str($environment_variable)->replace("{{{$sharedEnv}}}", $found->value);
            }
        }

        return str($environment_variable)->value();
    }

    private function get_environment_variables(?string $environment_variable = null): ?string
    {
        if (! $environment_variable) {
            return null;
        }

        return trim(decrypt($environment_variable));
    }

    private function set_environment_variables(?string $environment_variable = null): ?string
    {
        if (is_null($environment_variable) || $environment_variable === '') {
            return null;
        }
        $environment_variable = trim($environment_variable);
        $type = str($environment_variable)->after('{{')->before('.')->value();
        if (str($environment_variable)->startsWith('{{'.$type) && str($environment_variable)->endsWith('}}')) {
            return encrypt($environment_variable);
        }

        return encrypt($environment_variable);
    }

    /**
     * @return Attribute<string, string>
     */
    protected function key(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => ValidationPatterns::validatedEnvironmentVariableKey(
                ValidationPatterns::normalizeEnvironmentVariableKey($value)
            ),
        );
    }

    protected function updateIsShared(): void
    {
        $value = (string) data_get($this, 'value', '');
        $type = str($value)->after('{{')->before('.')->value();
        $isShared = str($value)->startsWith('{{'.$type) && str($value)->endsWith('}}');
        $this->forceFill(['is_shared' => $isShared]);
    }
}
