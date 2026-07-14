<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Http\Controllers\Concerns\ManagesDatabaseGeneralForm;
use App\Http\Controllers\Concerns\ManagesDatabaseImport;
use App\Http\Controllers\Concerns\ManagesResourceDanger;
use App\Http\Controllers\Concerns\ManagesResourceEnvironmentVariables;
use App\Http\Controllers\Concerns\ManagesResourceLimits;
use App\Http\Controllers\Concerns\ManagesResourceOperations;
use App\Http\Controllers\Concerns\ManagesResourceStorages;
use App\Http\Controllers\Concerns\ManagesResourceTags;
use App\Http\Controllers\Concerns\ManagesResourceWebhooks;
use App\Http\Controllers\Concerns\ResolvesProjectResources;
use App\Jobs\VolumeCloneJob;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\SwarmDocker;
use App\Support\DatabaseEngineRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Visus\Cuid2\Cuid2;

/**
 * React port of App\Livewire\Project\Database\Configuration's shell plus 6 of its 12 tabs —
 * the shared, database-applicable panels: Tags, Danger Zone, Webhooks, Resource Limits,
 * Resource Operations, and Servers (all previously nested Project\Shared\* Livewire
 * components rendered by route name). The remaining tabs (General per-engine forms,
 * Environment Variables, Persistent Storage, Healthcheck, Import Backup) stay on the
 * Livewire shell — same route-name split pattern as Phases 49/51.
 *
 * Tab notes, ported faithfully:
 * - Webhooks for a database is just the read-only deploy webhook URL — the manual git
 *   webhook secrets section (and therefore the save endpoint) is application-only.
 * - Servers for a database is the read-only primary-destination card — additional servers /
 *   redeploy / promote are application-only branches of the original component.
 * - Resource Operations' clone handles only the standalone-database branch here (the
 *   application/service branches belong to those routers' own conversions).
 * - Tag pruning after detach checks applications()/services() usage but not databases —
 *   a quirk of the original kept as-is (a tag used only by other databases still gets
 *   deleted from the team when detached from the last app/service).
 */
class ProjectDatabaseConfigurationController extends Controller
{
    use AuthorizesRequests;
    use ManagesDatabaseGeneralForm;
    use ManagesDatabaseImport;
    use ManagesResourceDanger;
    use ManagesResourceEnvironmentVariables;
    use ManagesResourceLimits;
    use ManagesResourceOperations;
    use ManagesResourceStorages;
    use ManagesResourceTags;
    use ManagesResourceWebhooks;
    use ResolvesProjectResources;

    public function show(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): Response|RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }
        $this->authorize('view', $database);

        $tab = str((string) $request->route()->getName())->after('project.database.')->value();
        $parameters = compact('project_uuid', 'environment_uuid', 'database_uuid');

        $props = [
            'tab' => $tab,
            'database' => [
                'uuid' => $database->uuid,
                'name' => $database->name,
                'status' => $database->status,
            ],
            'project' => ['uuid' => $project_uuid],
            'environment' => ['uuid' => $environment_uuid],
            'heading' => [
                'parameters' => $parameters,
                'dockerCleanupDefault' => true,
                'isFunctional' => (bool) $database->destination?->server?->isFunctional(),
                'isExited' => str($database->status)->startsWith('exited'),
            ],
            'configurationChecker' => [
                'isConfigurationChanged' => $database->isConfigurationChanged(),
                'isExited' => str($database->status)->startsWith('exited'),
                'configHash' => $database->config_hash,
                'diff' => [],
            ],
            'canUpdate' => auth()->user()->can('update', $database),
            'tabs' => $this->tabLinks($parameters, auth()->user()->can('update', $database)),
            'urls' => [
                'start' => route('project.database.start', $parameters),
                'stop' => route('project.database.stop', $parameters),
                'restart' => route('project.database.restart', $parameters),
                'checkStatus' => route('project.database.check-status', $parameters),
            ],
        ];

        return Inertia::render('Project/Database/Configuration', [...$props, ...$this->tabProps($tab, $database, $parameters)]);
    }

    public function storeTag(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->storeResourceTag($request, $database);
    }

    public function destroyTag(string $project_uuid, string $environment_uuid, string $database_uuid, string $tag_id): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->destroyResourceTag($database, $tag_id);
    }

    public function updateResourceLimits(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->applyResourceLimitsUpdate($request, $database);
    }

    public function move(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->moveResourceToEnvironment($request, $database, 'project.database.configuration', compact('project_uuid', 'environment_uuid', 'database_uuid'), 'database_uuid');
    }

    public function clone(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }
        $this->authorize('update', $database);

        $validated = Validator::make($request->all(), [
            'destination_id' => 'required|integer',
            'clone_volume_data' => 'boolean',
        ])->validate();

        $newDestination = StandaloneDocker::ownedByCurrentTeam()->find($validated['destination_id'])
            ?? SwarmDocker::ownedByCurrentTeam()->find($validated['destination_id']);
        if (! $newDestination) {
            return back()->withErrors(['destination_id' => 'Destination not found.']);
        }

        $cloneVolumeData = (bool) ($validated['clone_volume_data'] ?? false);
        $uuid = (string) new Cuid2;
        $clone = $database->replicate(['id', 'created_at', 'updated_at'])->fill([
            'uuid' => $uuid,
            'name' => $database->name.'-clone-'.$uuid,
            'status' => 'exited',
            'started_at' => null,
            'destination_id' => $newDestination->id,
        ]);
        $clone->save();

        foreach ($database->tags as $tag) {
            $clone->tags()->attach($tag->id);
        }

        $clone->persistentStorages()->delete();
        foreach ($database->persistentStorages()->get() as $volume) {
            $matchedPrefix = null;
            foreach (DatabaseEngineRegistry::all() as $engine) {
                if (str_starts_with($volume->name, $engine->volumeNamePrefix)) {
                    $matchedPrefix = $engine->volumeNamePrefix;
                    break;
                }
            }
            if ($matchedPrefix !== null) {
                $newName = $matchedPrefix.$clone->uuid;
            } elseif (str_starts_with($volume->name, $database->uuid)) {
                $newName = str($volume->name)->replace($database->uuid, $clone->uuid);
            } else {
                $newName = $clone->uuid.'-'.$volume->name;
            }

            $newVolume = $volume->replicate(['id', 'created_at', 'updated_at', 'uuid'])->fill([
                'name' => $newName,
                'resource_id' => $clone->id,
            ]);
            $newVolume->save();

            if ($cloneVolumeData) {
                try {
                    StopDatabase::dispatch($database);
                    VolumeCloneJob::dispatch($volume->name, $newVolume->name, $database->destination->server, $clone->destination->server, $newVolume);
                    StartDatabase::dispatch($database);
                } catch (\Exception $e) {
                    Log::error('Failed to copy volume data for '.$volume->name.': '.$e->getMessage());
                }
            }
        }

        foreach ($database->fileStorages()->get() as $storage) {
            $storage->replicate(['id', 'created_at', 'updated_at'])->fill([
                'resource_id' => $clone->id,
            ])->save();
        }

        foreach ($database->scheduledBackups()->get() as $backup) {
            $backup->replicate(['id', 'created_at', 'updated_at'])->fill([
                'uuid' => (string) new Cuid2,
                'database_id' => $clone->id,
                'database_type' => $clone->getMorphClass(),
                'team_id' => currentTeam()->id,
            ])->save();
        }

        foreach ($database->environment_variables()->get() as $variable) {
            $variable->replicate(['id', 'created_at', 'updated_at'])->fill([
                'resourceable_id' => $clone->id,
                'resourceable_type' => $clone->getMorphClass(),
            ])->save();
        }

        return redirect()->to(route('project.database.configuration', [
            'project_uuid' => $project_uuid,
            'environment_uuid' => $environment_uuid,
            'database_uuid' => $clone->uuid,
        ]).'#resource-operations');
    }

    public function destroy(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->destroyResource($request, $database, compact('project_uuid', 'environment_uuid'));
    }

    public function storeEnv(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->envStore($request, $database);
    }

    public function updateEnv(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid, string $env_id): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->envUpdate($request, $database, $env_id);
    }

    public function lockEnv(string $project_uuid, string $environment_uuid, string $database_uuid, string $env_id): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->envLock($database, $env_id);
    }

    public function destroyEnv(string $project_uuid, string $environment_uuid, string $database_uuid, string $env_id): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->envDestroy($database, $env_id);
    }

    public function bulkUpdateEnvs(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->envBulkUpdate($request, $database);
    }

    public function storagesVolumeStore(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->storeStorageVolume($request, $database);
    }

    public function storagesFileStore(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->storeStorageFile($request, $database);
    }

    public function storagesDirectoryStore(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->storeStorageDirectory($request, $database);
    }

    public function storagesVolumeUpdate(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid, string $volume_id): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->updateStorageVolume($request, $database, $this->resolveOwnedVolume($database, $volume_id));
    }

    public function storagesVolumeDestroy(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid, string $volume_id): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->destroyStorageVolume($request, $database, $this->resolveOwnedVolume($database, $volume_id));
    }

    public function storagesFileUpdate(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid, string $file_id): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->updateStorageFile($request, $database, $this->resolveOwnedFileVolume($database, $file_id));
    }

    public function storagesFileLoad(string $project_uuid, string $environment_uuid, string $database_uuid, string $file_id): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->loadStorageFile($database, $this->resolveOwnedFileVolume($database, $file_id));
    }

    public function storagesFileConvert(string $project_uuid, string $environment_uuid, string $database_uuid, string $file_id): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->convertStorageFile($database, $this->resolveOwnedFileVolume($database, $file_id));
    }

    public function storagesFileDestroy(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid, string $file_id): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->destroyStorageFile($request, $database, $this->resolveOwnedFileVolume($database, $file_id));
    }

    public function importCheckFileEndpoint(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->importCheckFile($request, $database);
    }

    public function importRunEndpoint(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->importRun($request, $database);
    }

    public function importCheckS3Endpoint(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->importCheckS3($request, $database);
    }

    public function importRestoreS3Endpoint(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->importRestoreS3($request, $database);
    }

    /**
     * Port of Project\Database\Health::submit() — saves the four probe numbers (and the
     * enabled flag as-is), then runs the original's config-hash side effect.
     */
    public function updateHealthcheck(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }
        $this->authorize('update', $database);

        $validated = Validator::make($request->all(), [
            'enabled' => 'boolean',
            'interval' => 'required|integer|min:1',
            'timeout' => 'required|integer|min:1',
            'retries' => 'required|integer|min:1',
            'startPeriod' => 'required|integer|min:0',
        ])->validate();

        $database->health_check_enabled = $request->boolean('enabled', (bool) $database->health_check_enabled);
        $database->health_check_interval = (int) $validated['interval'];
        $database->health_check_timeout = (int) $validated['timeout'];
        $database->health_check_retries = (int) $validated['retries'];
        $database->health_check_start_period = (int) $validated['startPeriod'];
        $database->save();

        $this->markHealthcheckConfigurationChanged($database);

        return back()->with('success', 'Health check updated. Restart the database to apply the changes.');
    }

    /** Port of Project\Database\Health::toggleHealthcheck(). */
    public function toggleHealthcheck(string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }
        $this->authorize('update', $database);

        $database->health_check_enabled = ! $database->health_check_enabled;
        $database->save();

        $this->markHealthcheckConfigurationChanged($database);

        return back()->with('success', 'Health check '.($database->health_check_enabled ? 'enabled' : 'disabled').'. Restart the database to apply the changes.');
    }

    private function markHealthcheckConfigurationChanged(Model $database): void
    {
        if (is_null($database->config_hash)) {
            $database->isConfigurationChanged(true);
        }
    }

    public function generalUpdate(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->updateDatabaseGeneral($request, $database);
    }

    public function generalProxyUpdate(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->updateDatabaseProxy($request, $database);
    }

    public function generalAdvancedUpdate(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->updateDatabaseAdvanced($request, $database);
    }

    public function generalSslUpdate(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->updateDatabaseSsl($request, $database);
    }

    public function generalSslRegenerate(string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        return $this->regenerateDatabaseSslCertificate($database);
    }

    public function generalInitScriptStore(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }
        if (! $database instanceof StandalonePostgresql) {
            abort(404);
        }

        return $this->storeDatabaseInitScript($request, $database);
    }

    public function generalInitScriptDestroy(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }
        if (! $database instanceof StandalonePostgresql) {
            abort(404);
        }

        return $this->destroyDatabaseInitScript($request, $database);
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function tabProps(string $tab, Model $database, array $parameters): array
    {
        return match ($tab) {
            'configuration' => $this->generalFormTabProps($database, $parameters, 'project.database'),
            'environment-variables' => $this->environmentVariablesTabProps($database, $parameters, 'project.database'),
            'persistent-storage' => $this->storagesTabProps($database, $parameters, 'project.database'),
            'import-backup' => $this->importTabProps($database, 'project.database', $parameters),
            'healthcheck' => [
                'healthcheck' => [
                    'enabled' => (bool) $database->health_check_enabled,
                    'interval' => $database->health_check_interval,
                    'timeout' => $database->health_check_timeout,
                    'retries' => $database->health_check_retries,
                    'startPeriod' => $database->health_check_start_period,
                ],
                'healthcheckUrls' => [
                    'update' => route('project.database.healthcheck.update', $parameters),
                    'toggle' => route('project.database.healthcheck.toggle', $parameters),
                ],
            ],
            'tags' => $this->tagsTabProps($database, $parameters, 'project.database'),
            'danger' => $this->dangerTabProps($database, $parameters, 'project.database'),
            'webhooks' => $this->webhooksTabProps($database, $parameters, 'project.database'),
            'resource-limits' => $this->resourceLimitsTabProps($database, $parameters, 'project.database'),
            'resource-operations' => $this->resourceOperationsTabProps($database, $parameters, 'project.database'),
            'servers' => [
                'primaryServer' => [
                    'name' => data_get($database, 'destination.server.name'),
                    'network' => data_get($database, 'destination.network'),
                    'status' => $database->realStatus(),
                ],
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<int, array{label: string, href: string}>
     */
    private function tabLinks(array $parameters, bool $canUpdate): array
    {
        $links = [
            ['label' => 'General', 'href' => route('project.database.configuration', $parameters)],
            ['label' => 'Environment Variables', 'href' => route('project.database.environment-variables', $parameters)],
            ['label' => 'Servers', 'href' => route('project.database.servers', $parameters)],
            ['label' => 'Persistent Storage', 'href' => route('project.database.persistent-storage', $parameters)],
        ];
        if ($canUpdate) {
            $links[] = ['label' => 'Import Backup', 'href' => route('project.database.import-backup', $parameters)];
        }

        return [
            ...$links,
            ['label' => 'Webhooks', 'href' => route('project.database.webhooks', $parameters)],
            ['label' => 'Healthcheck', 'href' => route('project.database.healthcheck', $parameters)],
            ['label' => 'Resource Limits', 'href' => route('project.database.resource-limits', $parameters)],
            ['label' => 'Resource Operations', 'href' => route('project.database.resource-operations', $parameters)],
            ['label' => 'Metrics', 'href' => route('project.database.metrics', $parameters)],
            ['label' => 'Tags', 'href' => route('project.database.tags', $parameters)],
            ['label' => 'Danger Zone', 'href' => route('project.database.danger', $parameters)],
        ];
    }
}
