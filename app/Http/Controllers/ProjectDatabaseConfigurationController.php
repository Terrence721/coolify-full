<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Http\Controllers\Concerns\ResolvesProjectResources;
use App\Jobs\DeleteResourceJob;
use App\Jobs\VolumeCloneJob;
use App\Models\Environment;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Models\Tag;
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
    use ResolvesProjectResources;

    private const LIMIT_RULES = [
        'limitsMemory' => ['required', 'string', 'regex:/^(0|\d+[bBkKmMgG])$/'],
        'limitsMemorySwap' => ['required', 'string', 'regex:/^(0|\d+[bBkKmMgG])$/'],
        'limitsMemorySwappiness' => 'required|integer|min:0|max:100',
        'limitsMemoryReservation' => ['required', 'string', 'regex:/^(0|\d+[bBkKmMgG])$/'],
        'limitsCpus' => ['nullable', 'regex:/^\d*\.?\d+$/'],
        'limitsCpuset' => ['nullable', 'regex:/^\d+([,-]\d+)*$/'],
        'limitsCpuShares' => 'nullable|integer|min:0',
    ];

    private const LIMIT_MESSAGES = [
        'limitsMemory.regex' => 'Maximum Memory Limit must be a number followed by a unit (b, k, m, g). Example: 256m, 1g. Use 0 for unlimited.',
        'limitsMemorySwap.regex' => 'Maximum Swap Limit must be a number followed by a unit (b, k, m, g). Example: 256m, 1g. Use 0 for unlimited.',
        'limitsMemoryReservation.regex' => 'Soft Memory Limit must be a number followed by a unit (b, k, m, g). Example: 256m, 1g. Use 0 for unlimited.',
        'limitsCpus.regex' => 'Number of CPUs must be a number (integer or decimal). Example: 0.5, 2.',
        'limitsCpuset.regex' => 'CPU sets must be a comma-separated list of CPU numbers or ranges. Example: 0-2 or 0,1,3.',
        'limitsMemorySwappiness.integer' => 'Swappiness must be a whole number between 0 and 100.',
        'limitsMemorySwappiness.min' => 'Swappiness must be between 0 and 100.',
        'limitsMemorySwappiness.max' => 'Swappiness must be between 0 and 100.',
        'limitsCpuShares.integer' => 'CPU Weight must be a whole number.',
        'limitsCpuShares.min' => 'CPU Weight must be a positive number.',
    ];

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
        $this->authorize('update', $database);

        $validated = Validator::make($request->all(), [
            'tags' => 'required_without:tag_id|nullable|string|min:2',
            'tag_id' => 'required_without:tags|nullable|integer',
        ])->validate();

        if (filled($validated['tag_id'] ?? null)) {
            $tag = Tag::ownedByCurrentTeam()->findOrFail((int) $validated['tag_id']);
            if ($database->tags()->where('id', $tag->id)->exists()) {
                return back()->with('error', "Tag {$tag->name} already added.");
            }
            $database->tags()->attach($tag->id);

            return back()->with('success', 'Tag added.');
        }

        $skipped = [];
        foreach (str($validated['tags'])->trim()->explode(' ') as $name) {
            $name = strip_tags($name);
            if (strlen($name) < 2) {
                $skipped[] = "Tag {$name} is invalid (min length is 2).";

                continue;
            }
            if ($database->tags()->where('name', $name)->exists()) {
                $skipped[] = "Tag {$name} already added.";

                continue;
            }
            $tag = Tag::ownedByCurrentTeam()->where('name', $name)->first()
                ?? Tag::create(['name' => $name, 'team_id' => currentTeam()->id]);
            $database->tags()->attach($tag->id);
        }

        if ($skipped !== []) {
            return back()->with('error', implode(' ', $skipped));
        }

        return back()->with('success', 'Tags added.');
    }

    public function destroyTag(string $project_uuid, string $environment_uuid, string $database_uuid, string $tag_id): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }
        $this->authorize('update', $database);

        $database->tags()->detach($tag_id);
        $tag = Tag::ownedByCurrentTeam()->find($tag_id);
        if ($tag && $tag->applications()->count() == 0 && $tag->services()->count() == 0) {
            $tag->delete();
        }

        return back()->with('success', 'Tag deleted.');
    }

    public function updateResourceLimits(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }
        $this->authorize('update', $database);

        // Same pre-validation defaulting as the original component
        $input = $request->all();
        $input['limitsMemory'] = filled($input['limitsMemory'] ?? null) ? $input['limitsMemory'] : '0';
        $input['limitsMemorySwap'] = filled($input['limitsMemorySwap'] ?? null) ? $input['limitsMemorySwap'] : '0';
        $input['limitsMemorySwappiness'] = ($input['limitsMemorySwappiness'] ?? '') === '' ? 60 : $input['limitsMemorySwappiness'];
        $input['limitsMemoryReservation'] = filled($input['limitsMemoryReservation'] ?? null) ? $input['limitsMemoryReservation'] : '0';
        $input['limitsCpus'] = filled($input['limitsCpus'] ?? null) ? $input['limitsCpus'] : '0';
        $input['limitsCpuset'] = ($input['limitsCpuset'] ?? '') === '' ? null : $input['limitsCpuset'];
        $input['limitsCpuShares'] = ($input['limitsCpuShares'] ?? '') === '' ? 1024 : $input['limitsCpuShares'];

        $validated = Validator::make($input, self::LIMIT_RULES, self::LIMIT_MESSAGES)->validate();

        $database->update([
            'limits_cpus' => $validated['limitsCpus'],
            'limits_cpuset' => $validated['limitsCpuset'] ?? null,
            'limits_cpu_shares' => (int) ($validated['limitsCpuShares'] ?? 1024),
            'limits_memory' => $validated['limitsMemory'],
            'limits_memory_swap' => $validated['limitsMemorySwap'],
            'limits_memory_swappiness' => (int) $validated['limitsMemorySwappiness'],
            'limits_memory_reservation' => $validated['limitsMemoryReservation'],
        ]);

        return back()->with('success', 'Resource limits updated.');
    }

    public function move(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }
        $this->authorize('update', $database);

        $validated = Validator::make($request->all(), [
            'environment_id' => 'required|integer',
        ])->validate();

        $newEnvironment = Environment::ownedByCurrentTeam()->findOrFail($validated['environment_id']);
        $database->update(['environment_id' => $newEnvironment->id]);

        return redirect()->to(route('project.database.configuration', [
            'project_uuid' => $newEnvironment->project->uuid,
            'environment_uuid' => $newEnvironment->uuid,
            'database_uuid' => $database->uuid,
        ]).'#resource-operations');
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

        $validated = Validator::make($request->all(), [
            'password' => 'required|string',
            'delete_volumes' => 'nullable|boolean',
            'delete_connected_networks' => 'nullable|boolean',
            'delete_configurations' => 'nullable|boolean',
            'docker_cleanup' => 'nullable|boolean',
        ])->validate();

        if (! verifyPasswordConfirmation($validated['password'])) {
            return back()->with('error', 'The provided password is incorrect.');
        }

        $this->authorize('delete', $database);

        $database->delete();
        DeleteResourceJob::dispatch(
            $database,
            $request->boolean('delete_volumes'),
            $request->boolean('delete_connected_networks'),
            $request->boolean('delete_configurations'),
            $request->boolean('docker_cleanup'),
        );

        return redirect()->route('project.resource.index', [
            'project_uuid' => $project_uuid,
            'environment_uuid' => $environment_uuid,
        ]);
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function tabProps(string $tab, Model $database, array $parameters): array
    {
        return match ($tab) {
            'tags' => [
                'tags' => $database->tags->map(fn (Tag $tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'destroyUrl' => route('project.database.tags.destroy', [...$parameters, 'tag_id' => $tag->id]),
                ])->values(),
                'availableTags' => Tag::ownedByCurrentTeam()->get()
                    ->reject(fn (Tag $tag) => $database->tags->contains($tag))
                    ->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name])
                    ->values(),
                'tagsStoreUrl' => route('project.database.tags.store', $parameters),
            ],
            'danger' => [
                'resourceName' => $database->name ?? 'Database',
                'canDelete' => auth()->user()->can('delete', $database),
                'destroyUrl' => route('project.database.destroy', $parameters),
            ],
            'webhooks' => [
                'deployWebhook' => generateDeployWebhook($database),
            ],
            'resource-limits' => [
                'limits' => [
                    'limitsCpus' => $database->limits_cpus,
                    'limitsCpuset' => $database->limits_cpuset,
                    'limitsCpuShares' => $database->limits_cpu_shares,
                    'limitsMemory' => $database->limits_memory,
                    'limitsMemorySwap' => $database->limits_memory_swap,
                    'limitsMemorySwappiness' => $database->limits_memory_swappiness,
                    'limitsMemoryReservation' => $database->limits_memory_reservation,
                ],
                'limitsUpdateUrl' => route('project.database.resource-limits.update', $parameters),
            ],
            'resource-operations' => [
                'servers' => currentTeam()->servers
                    ->filter(fn ($server) => ! $server->isBuildServer())
                    ->map(fn ($server) => [
                        'id' => $server->id,
                        'name' => $server->name,
                        'ip' => $server->ip,
                        'destinations' => $server->destinations()->map(fn ($destination) => [
                            'id' => $destination->id,
                            'name' => $destination->name,
                        ])->values(),
                    ])->values(),
                'projects' => Project::ownedByCurrentTeamCached()->map(fn ($project) => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'environments' => $project->environments->map(fn ($environment) => [
                        'id' => $environment->id,
                        'name' => $environment->name,
                    ])->values(),
                ])->values(),
                'currentProjectId' => $database->environment->project->id,
                'currentEnvironmentId' => $database->environment->id,
                'operationUrls' => [
                    'clone' => route('project.database.clone', $parameters),
                    'move' => route('project.database.move', $parameters),
                ],
            ],
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
