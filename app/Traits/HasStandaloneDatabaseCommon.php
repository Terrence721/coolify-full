<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\ScheduledDatabaseBackup;
use App\Models\SslCertificate;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Shared behavior for the 8 Standalone* database models (Postgresql, Mysql, Mariadb,
 * Mongodb, Redis, Keydb, Dragonfly, Clickhouse). Each model still owns its own table,
 * $fillable/$casts, `type()`, `internalDbUrl()`/`externalDbUrl()`, and `booted()`'s
 * `created` hook (which provisions engine-specific persistent volumes) — those genuinely
 * differ per engine. Everything below was byte-identical across all 8 models.
 *
 * `bootHasStandaloneDatabaseCommon()` is auto-invoked by Eloquent's trait-boot
 * convention alongside each model's own `booted()`/`created` hook.
 */
trait HasStandaloneDatabaseCommon
{
    public static function bootHasStandaloneDatabaseCommon(): void
    {
        static::forceDeleting(function ($database) {
            $database->persistentStorages()->delete();
            $database->scheduledBackups()->delete();
            $database->environment_variables()->delete();
            $database->tags()->detach();
        });

        static::saving(function ($database) {
            if ($database->isDirty('status')) {
                $database->last_online_at = now();
            }
        });
    }

    /**
     * Get query builder for databases of this type owned by the current team.
     * If you need all databases without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    public static function ownedByCurrentTeam()
    {
        $team = currentTeam();

        if (! $team) {
            return static::query()->whereRaw('1 = 0')->orderBy('name');
        }

        return static::whereRelation('environment.project.team', 'id', $team->id)->orderBy('name');
    }

    /**
     * Get all databases of this type owned by the current team (cached for request duration).
     */
    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return static::ownedByCurrentTeam()->get();
        });
    }

    protected function serverStatus(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->destination->server->isFunctional();
            }
        );
    }

    /**
     * Extra config fields (beyond image + ports_mappings) that should invalidate the
     * cached config hash when changed. Override in models with an engine-specific conf
     * file or init args (e.g. `return $this->postgres_initdb_args.$this->postgres_host_auth_method;`).
     */
    protected function configHashExtra(): string
    {
        return '';
    }

    public function isConfigurationChanged(bool $save = false)
    {
        $newConfigHash = $this->image.$this->ports_mappings.$this->configHashExtra();
        $newConfigHash .= $this->healthCheckConfigurationHash();
        $newConfigHash .= json_encode($this->environment_variables()->get('value')->sort());
        $newConfigHash = md5($newConfigHash);
        $oldConfigHash = data_get($this, 'config_hash');
        if ($oldConfigHash === null) {
            if ($save) {
                $this->config_hash = $newConfigHash;
                $this->save();
            }

            return true;
        }
        if ($oldConfigHash === $newConfigHash) {
            return false;
        } else {
            if ($save) {
                $this->config_hash = $newConfigHash;
                $this->save();
            }

            return true;
        }
    }

    public function isRunning()
    {
        return (bool) str($this->status)->contains('running');
    }

    public function isExited()
    {
        return (bool) str($this->status)->startsWith('exited');
    }

    public function workdir()
    {
        return database_configuration_dir()."/{$this->uuid}";
    }

    public function deleteConfigurations()
    {
        $server = data_get($this, 'destination.server');
        $workdir = $this->workdir();
        if (str($workdir)->endsWith($this->uuid)) {
            instant_remote_process(['rm -rf '.$this->workdir()], $server, false);
        }
    }

    public function deleteVolumes()
    {
        $persistentStorages = $this->persistentStorages()->get() ?? collect();
        if ($persistentStorages->count() === 0) {
            return;
        }
        $server = data_get($this, 'destination.server');
        foreach ($persistentStorages as $storage) {
            instant_remote_process(['docker volume rm -f '.escapeshellarg($storage->name)], $server, false);
        }
    }

    public function realStatus()
    {
        return $this->getRawOriginal('status');
    }

    public function status(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (str($value)->contains('(')) {
                    $status = str($value)->before('(')->trim()->value();
                    $health = str($value)->after('(')->before(')')->trim()->value() ?? 'unhealthy';
                } elseif (str($value)->contains(':')) {
                    $status = str($value)->before(':')->trim()->value();
                    $health = str($value)->after(':')->trim()->value() ?? 'unhealthy';
                } else {
                    $status = $value;
                    $health = 'unhealthy';
                }

                return "$status:$health";
            },
            get: function ($value) {
                if (str($value)->contains('(')) {
                    $status = str($value)->before('(')->trim()->value();
                    $health = str($value)->after('(')->before(')')->trim()->value() ?? 'unhealthy';
                } elseif (str($value)->contains(':')) {
                    $status = str($value)->before(':')->trim()->value();
                    $health = str($value)->after(':')->trim()->value() ?? 'unhealthy';
                } else {
                    $status = $value;
                    $health = 'unhealthy';
                }

                return "$status:$health";
            },
        );
    }

    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function project(): mixed
    {
        return data_get($this, 'environment.project');
    }

    public function team()
    {
        return data_get($this, 'environment.project.team');
    }

    public function link()
    {
        if (data_get($this, 'environment.project.uuid')) {
            return route('project.database.configuration', [
                'project_uuid' => data_get($this, 'environment.project.uuid'),
                'environment_uuid' => data_get($this, 'environment.uuid'),
                'database_uuid' => data_get($this, 'uuid'),
            ]);
        }

        return null;
    }

    public function isLogDrainEnabled()
    {
        return data_get($this, 'is_log_drain_enabled', false);
    }

    public function portsMappings(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === '' ? null : $value,
        );
    }

    public function portsMappingsArray(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->ports_mappings)
                ? []
                : explode(',', $this->ports_mappings),
        );
    }

    public function databaseType(): Attribute
    {
        return new Attribute(
            get: fn () => $this->type(),
        );
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }

    public function fileStorages()
    {
        return $this->morphMany(LocalFileVolume::class, 'resource');
    }

    public function sslCertificates()
    {
        return $this->morphMany(SslCertificate::class, 'resource');
    }

    public function destination()
    {
        return $this->morphTo();
    }

    public function runtime_environment_variables()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable');
    }

    public function environment_variables()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable');
    }

    public function scheduledBackups()
    {
        return $this->morphMany(ScheduledDatabaseBackup::class, 'database');
    }

    public function isBackupSolutionAvailable()
    {
        return true;
    }
}
