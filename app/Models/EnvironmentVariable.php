<?php

namespace App\Models;

use App\Models\EnvironmentVariable as ModelsEnvironmentVariable;
use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
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
 */
#[OA\Schema(
    description: 'Environment Variable model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'uuid' => ['type' => 'string'],
        'resourceable_type' => ['type' => 'string'],
        'resourceable_id' => ['type' => 'integer'],
        'is_literal' => ['type' => 'boolean'],
        'is_multiline' => ['type' => 'boolean'],
        'is_preview' => ['type' => 'boolean'],
        'is_runtime' => ['type' => 'boolean'],
        'is_buildtime' => ['type' => 'boolean'],
        'is_shared' => ['type' => 'boolean'],
        'is_shown_once' => ['type' => 'boolean'],
        'key' => ['type' => 'string'],
        'value' => ['type' => 'string'],
        'real_value' => ['type' => 'string'],
        'comment' => ['type' => 'string', 'nullable' => true],
        'version' => ['type' => 'string'],
        'created_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
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

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

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

    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value = null): ?string => $this->get_environment_variables($value),
            set: fn (?string $value = null): ?string => $this->set_environment_variables($value),
        );
    }

    /**
     * Get the parent resourceable model.
     */
    public function resourceable()
    {
        return $this->morphTo();
    }

    public function resource()
    {
        return $this->resourceable()->getResults();
    }

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
                if (! $resource instanceof Model) {
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

    protected function isReallyRequired(): Attribute
    {
        return Attribute::make(
            get: fn () => (bool) data_get($this, 'is_required') && str(data_get($this, 'real_value'))->isEmpty(),
        );
    }

    protected function isBuildpackControl(): Attribute
    {
        return Attribute::make(
            get: fn () => self::isBuildpackControlKey(data_get($this, 'key')),
        );
    }

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

    public function get_real_environment_variables_with_server(?string $environment_variable = null, $resource = null, $server = null)
    {
        return $this->get_real_environment_variables_internal($environment_variable, $resource, $server);
    }

    public function getResolvedValueWithServer($server = null)
    {
        if (! $this->relationLoaded('resourceable')) {
            $this->load('resourceable');
        }
        $resource = $this->resourceable()->getResults();
        if (! $resource) {
            return null;
        }
        if (! $resource instanceof Model) {
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

    private function get_real_environment_variables(?string $environment_variable = null, $resource = null)
    {
        return $this->get_real_environment_variables_internal($environment_variable, $resource);
    }

    private function get_real_environment_variables_internal(?string $environment_variable = null, $resource = null, $serverOverride = null)
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
                $id = $resource->environment->id;
            } elseif ($type->value() === 'project') {
                $id = $resource->environment->project->id;
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
        if (is_null($environment_variable) && $environment_variable === '') {
            return null;
        }
        $environment_variable = trim($environment_variable);
        $type = str($environment_variable)->after('{{')->before('.')->value();
        if (str($environment_variable)->startsWith('{{'.$type) && str($environment_variable)->endsWith('}}')) {
            return encrypt($environment_variable);
        }

        return encrypt($environment_variable);
    }

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
