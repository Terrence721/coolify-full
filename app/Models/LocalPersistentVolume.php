<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * @property string $name
 * @property string $mount_path
 * @property string|null $host_path
 * @property int $id
 * @property string|null $container_id
 * @property string|null $resource_type
 * @property int|null $resource_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property bool $is_preview_suffix_enabled
 * @property string $uuid
 * @property-read mixed $image
 * @property-read Application|StandaloneDatabaseInstance|ServiceApplication|ServiceDatabase|null $resource
 * @property-read mixed $sanitized_name
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocalPersistentVolume newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocalPersistentVolume newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocalPersistentVolume query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocalPersistentVolume whereContainerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocalPersistentVolume whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocalPersistentVolume whereHostPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocalPersistentVolume whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocalPersistentVolume whereIsPreviewSuffixEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocalPersistentVolume whereMountPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocalPersistentVolume whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocalPersistentVolume whereResourceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocalPersistentVolume whereResourceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocalPersistentVolume whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocalPersistentVolume whereUuid($value)
 *
 * @mixin \Eloquent
 */
class LocalPersistentVolume extends BaseModel
{
    protected $fillable = [
        'name',
        'mount_path',
        'host_path',
        'container_id',
        'resource_type',
        'resource_id',
        'is_preview_suffix_enabled',
    ];

    protected $casts = [
        'is_preview_suffix_enabled' => 'boolean',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function resource(): MorphTo
    {
        return $this->morphTo('resource');
    }

    protected function customizeName(string $value): string
    {
        return str($value)->trim()->toString();
    }

    /**
     * @return Attribute<string, string>
     */
    protected function mountPath(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => str($value)->trim()->start('/')->toString()
        );
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function hostPath(): Attribute
    {
        return Attribute::make(
            set: function (?string $value) {
                if ($value) {
                    return str($value)->trim()->start('/')->toString();
                } else {
                    return $value;
                }
            }
        );
    }

    // Check if this volume belongs to a service resource
    public function isServiceResource(): bool
    {
        return in_array($this->resource_type, [
            'App\Models\ServiceApplication',
            'App\Models\ServiceDatabase',
        ]);
    }

    // Check if this volume belongs to a dockercompose application
    public function isDockerComposeResource(): bool
    {
        if ($this->resource_type !== 'App\Models\Application') {
            return false;
        }

        // Only access relationship if already eager loaded to avoid N+1
        if (! $this->relationLoaded('resource')) {
            return false;
        }

        $application = $this->resource;
        if (! $application) {
            return false;
        }

        return data_get($application, 'build_pack') === 'dockercompose';
    }

    // Determine if this volume should be read-only in the UI
    // Service volumes and dockercompose application volumes are read-only
    // (users should edit compose file directly)
    public function shouldBeReadOnlyInUI(): bool
    {
        // All service volumes should be read-only in UI
        if ($this->isServiceResource()) {
            return true;
        }

        // All dockercompose application volumes should be read-only in UI
        if ($this->isDockerComposeResource()) {
            return true;
        }

        // Check for explicit :ro flag in compose (existing logic)
        return $this->isReadOnlyVolume();
    }

    // Check if this volume is read-only by parsing the docker-compose content
    public function isReadOnlyVolume(): bool
    {
        try {
            // Get the resource (can be application, service, or database)
            $resource = $this->resource;
            if (! $resource) {
                return false;
            }

            // Only check for services
            if (! method_exists($resource, 'service')) {
                return false;
            }

            $actualService = $resource->service()->first();
            $dockerComposeRaw = data_get($actualService, 'docker_compose_raw');
            if (! $actualService || ! $dockerComposeRaw) {
                return false;
            }

            // Parse the docker-compose content
            $compose = Yaml::parse($dockerComposeRaw);
            if (! isset($compose['services'])) {
                return false;
            }

            // Find the service that this volume belongs to
            $serviceName = $resource->name;
            if (! isset($compose['services'][$serviceName]['volumes'])) {
                return false;
            }

            $volumes = $compose['services'][$serviceName]['volumes'];

            // Check each volume to find a match
            // Note: We match on mount_path (container path) only, since host paths get transformed
            foreach ($volumes as $volume) {
                // Volume can be string like "host:container:ro" or "host:container"
                if (is_string($volume)) {
                    $parts = explode(':', $volume);

                    // Check if this volume matches our mount_path
                    if (count($parts) >= 2) {
                        $containerPath = $parts[1];
                        $options = $parts[2] ?? null;

                        // Match based on mount_path
                        // Remove leading slash from mount_path if present for comparison
                        $mountPath = str($this->mount_path)->ltrim('/')->toString();
                        $containerPathClean = str($containerPath)->ltrim('/')->toString();

                        if ($mountPath === $containerPathClean || $this->mount_path === $containerPath) {
                            return $options === 'ro';
                        }
                    }
                } elseif (is_array($volume)) {
                    // Long-form syntax: { type: bind/volume, source: ..., target: ..., read_only: true }
                    $containerPath = data_get($volume, 'target');
                    $readOnly = data_get($volume, 'read_only', false);

                    // Match based on mount_path
                    // Remove leading slash from mount_path if present for comparison
                    $mountPath = str($this->mount_path)->ltrim('/')->toString();
                    $containerPathClean = str($containerPath)->ltrim('/')->toString();

                    if ($mountPath === $containerPathClean || $this->mount_path === $containerPath) {
                        return $readOnly === true;
                    }
                }
            }

            return false;
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in isReadOnlyVolume().', ['error' => $e->getMessage()]);

            ray($e->getMessage(), 'Error checking read-only persistent volume');

            return false;
        }
    }
}
