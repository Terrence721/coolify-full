<?php

declare(strict_types=1);

namespace App\Traits;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Models\Server;
use Exception;

/**
 * Shared behavior for the per-database General Livewire siblings
 * (Project\Database\{Postgresql,Mysql,Mariadb,Mongodb,Redis,Keydb,Dragonfly,Clickhouse}\General).
 * `mount()`, `instantSaveAdvanced()`, `instantSave()`, and `refresh()` were byte-identical
 * (or, for `mount()`, differed only by a safe `Exception`-vs-`Throwable` catch-type widening)
 * across all 8 components. `submit()` here is the version shared by 5 of the 8 (Postgresql,
 * Mysql, Mariadb, Dragonfly, Clickhouse) — Mongodb, Keydb, and Redis each override it with a
 * genuinely different body (extra field normalization, a different authorization ability, or
 * — for Redis — bespoke env-var persistence), so those three keep their own `submit()` rather
 * than being forced into this shared shape.
 *
 * Consumers must declare a typed `public Model $database` plus `syncData(bool $toModel = false)`,
 * `rules()`, `messages()`, and `render()` (or rely on Livewire's naming-convention default).
 */
trait HasDatabaseGeneralForm
{
    public ?Server $server = null;

    public string $name;

    public ?string $description = null;

    public string $image;

    public ?string $portsMappings = null;

    public ?bool $isPublic = null;

    public mixed $publicPort = null;

    public mixed $publicPortTimeout = 3600;

    public bool $isLogDrainEnabled = false;

    public ?string $customDockerRunOptions = null;

    public function mount()
    {
        try {
            $this->authorize('view', $this->database);
            $this->syncData();
            $this->server = data_get($this->database, 'destination.server');
            if (! $this->server) {
                $this->dispatch('error', 'Database destination server is not configured.');

                return;
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSaveAdvanced()
    {
        try {
            $this->authorize('update', $this->database);

            if (! $this->server->isLogDrainEnabled()) {
                $this->isLogDrainEnabled = false;
                $this->dispatch('error', 'Log drain is not enabled on the server. Please enable it first.');

                return;
            }
            $this->syncData(true);
            $this->dispatch('success', 'Database updated.');
            $this->dispatch('success', 'You need to restart the service for the changes to take effect.');
        } catch (Exception $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->database);

            if ($this->portsMappings) {
                $this->portsMappings = str($this->portsMappings)->replace(' ', '')->trim()->toString();
            }
            if (str($this->publicPort)->isEmpty()) {
                $this->publicPort = null;
            }
            $this->syncData(true);
            $this->dispatch('success', 'Database updated.');
            $this->dispatch('databaseUpdated');
        } catch (Exception $e) {
            return handleError($e, $this);
        } finally {
            if (is_null($this->database->config_hash)) {
                $this->database->isConfigurationChanged(true);
            } else {
                $this->dispatch('configurationChanged');
            }
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->database);

            if ($this->isPublic && ! $this->publicPort) {
                $this->dispatch('error', 'Public port is required.');
                $this->isPublic = false;

                return;
            }
            if ($this->isPublic && ! str($this->database->status)->startsWith('running')) {
                $this->dispatch('error', 'Database must be started to be publicly accessible.');
                $this->isPublic = false;

                return;
            }
            $this->syncData(true);
            if ($this->isPublic) {
                StartDatabaseProxy::run($this->database);
                $this->dispatch('success', 'Database is now publicly accessible.');
            } else {
                StopDatabaseProxy::run($this->database);
                $this->dispatch('success', 'Database is no longer publicly accessible.');
            }
            $this->dispatch('databaseUpdated');
        } catch (\Throwable $e) {
            $this->isPublic = ! $this->isPublic;
            $this->syncData(true);

            return handleError($e, $this);
        }
    }

    public function refresh(): void
    {
        $this->database->refresh();
        $this->syncData();
    }
}
