<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Jobs\VolumeCloneJob;
use App\Models\Project;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Visus\Cuid2\Cuid2;

class EnvironmentController extends Controller
{
    use AuthorizesRequests;

    public function edit(string $project_uuid, string $environment_uuid): Response
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $environment = $project->environments()->where('uuid', $environment_uuid)->firstOrFail();

        return Inertia::render('Project/EnvironmentEdit', [
            'project' => [
                'uuid' => $project->uuid,
                'name' => $project->name,
            ],
            'environment' => [
                'uuid' => $environment->uuid,
                'name' => $environment->name,
                'description' => $environment->description,
                'isEmpty' => $environment->isEmpty(),
            ],
            'canUpdate' => auth()->user()?->can('update', $environment) ?? false,
            'canDelete' => auth()->user()?->can('delete', $environment) ?? false,
            'projectShowUrl' => route('project.show', ['project_uuid' => $project->uuid]),
            'resourceIndexUrl' => route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]),
            'updateUrl' => route('project.environment.update', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]),
            'deleteUrl' => route('project.environment.destroy', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]),
        ]);
    }

    public function update(Request $request, string $project_uuid, string $environment_uuid): RedirectResponse
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $environment = $project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        $this->authorize('update', $environment);

        $validated = Validator::make(
            $request->all(),
            [
                'name' => ValidationPatterns::nameRules(),
                'description' => ValidationPatterns::descriptionRules(),
            ],
            ValidationPatterns::combinedMessages(),
        )->validate();

        $environment->update($validated);

        return redirect()->route('project.environment.edit', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
        ]);
    }

    public function destroy(string $project_uuid, string $environment_uuid): RedirectResponse
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $environment = $project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        $this->authorize('delete', $environment);

        if (! $environment->isEmpty()) {
            return back()->with('error', "Environment {$environment->name} has defined resources, please delete them first.");
        }

        $environment->delete();

        return redirect()->route('project.show', ['project_uuid' => $project->uuid]);
    }

    public function cloneMe(string $project_uuid, string $environment_uuid): Response
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $environment = $project->environments()->where('uuid', $environment_uuid)->firstOrFail();

        $servers = currentTeam()->servers()->get()->reject(fn ($server) => $server->isBuildServer());

        $destinations = [];
        foreach ($servers->sortBy('id') as $server) {
            foreach ($server->destinations() as $destination) {
                $destinations[] = [
                    'serverId' => $server->id,
                    'serverName' => $server->name,
                    'destinationId' => $destination->id,
                    'destinationName' => $destination->name,
                ];
            }
        }

        $resources = [];
        foreach ($environment->applications->sortBy('name') as $application) {
            $resources[] = ['name' => $application->name, 'type' => 'Application', 'description' => $application->description];
        }
        foreach ($environment->databases()->sortBy('name') as $database) {
            $resources[] = ['name' => $database->name, 'type' => 'Database', 'description' => $database->description];
        }
        foreach ($environment->services->sortBy('name') as $service) {
            $resources[] = ['name' => $service->name, 'type' => 'Service', 'description' => $service->description];
        }

        return Inertia::render('Project/CloneMe', [
            'project' => ['uuid' => $project->uuid, 'name' => $project->name],
            'environment' => ['uuid' => $environment->uuid, 'name' => $environment->name],
            'destinations' => $destinations,
            'resources' => $resources,
            'defaultName' => str($project->name.'-clone-'.(string) new Cuid2)->slug(),
            'cloneUrl' => route('project.clone-me.store', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]),
        ]);
    }

    public function clone(Request $request, string $project_uuid, string $environment_uuid): RedirectResponse
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $sourceEnvironment = $project->environments()->where('uuid', $environment_uuid)->firstOrFail();

        $validated = Validator::make(
            $request->all(),
            [
                'type' => 'required|string|in:project,environment',
                'destination_id' => 'required|integer',
                'name' => ValidationPatterns::nameRules(),
                'clone_volume_data' => 'boolean',
            ],
            array_merge(ValidationPatterns::combinedMessages(), [
                'destination_id.required' => 'Please select a server & destination.',
            ]),
        )->validate();

        $cloneVolumeData = (bool) ($validated['clone_volume_data'] ?? false);

        try {
            if ($validated['type'] === 'project') {
                $foundProject = Project::where('name', $validated['name'])->first();
                if ($foundProject) {
                    throw new \Exception('Project with the same name already exists.');
                }
                $newProject = Project::create([
                    'name' => $validated['name'],
                    'team_id' => currentTeam()->id,
                    'description' => $project->description.' (clone)',
                ]);
                if ($sourceEnvironment->name !== 'production') {
                    $newProject->environments()->create([
                        'name' => $sourceEnvironment->name,
                        'uuid' => (string) new Cuid2,
                    ]);
                }
                $targetProject = $newProject;
                $targetEnvironment = $newProject->environments->where('name', $sourceEnvironment->name)->first();
            } else {
                $foundEnv = $project->environments()->where('name', $validated['name'])->first();
                if ($foundEnv) {
                    throw new \Exception('Environment with the same name already exists.');
                }
                $targetProject = $project;
                $targetEnvironment = $project->environments()->create([
                    'name' => $validated['name'],
                    'uuid' => (string) new Cuid2,
                ]);
            }

            $servers = currentTeam()->servers()->get()->reject(fn ($server) => $server->isBuildServer());
            $selectedDestination = null;
            foreach ($servers as $server) {
                $selectedDestination = $server->destinations()->firstWhere('id', $validated['destination_id']);
                if ($selectedDestination) {
                    break;
                }
            }

            foreach ($sourceEnvironment->applications as $application) {
                clone_application($application, $selectedDestination, [
                    'environment_id' => $targetEnvironment->id,
                ], $cloneVolumeData);
            }

            foreach ($sourceEnvironment->databases() as $database) {
                $uuid = (string) new Cuid2;
                $newDatabase = $database->replicate([
                    'id',
                    'created_at',
                    'updated_at',
                ])->fill([
                    'uuid' => $uuid,
                    'status' => 'exited',
                    'started_at' => null,
                    'environment_id' => $targetEnvironment->id,
                    'destination_id' => $validated['destination_id'],
                ]);
                $newDatabase->save();

                foreach ($database->tags as $tag) {
                    $newDatabase->tags()->attach($tag->id);
                }

                $newDatabase->persistentStorages()->delete();
                foreach ($database->persistentStorages()->get() as $volume) {
                    $originalName = $volume->name;
                    $newName = match (true) {
                        str_starts_with($originalName, 'postgres-data-') => 'postgres-data-'.$newDatabase->uuid,
                        str_starts_with($originalName, 'mysql-data-') => 'mysql-data-'.$newDatabase->uuid,
                        str_starts_with($originalName, 'redis-data-') => 'redis-data-'.$newDatabase->uuid,
                        str_starts_with($originalName, 'clickhouse-data-') => 'clickhouse-data-'.$newDatabase->uuid,
                        str_starts_with($originalName, 'mariadb-data-') => 'mariadb-data-'.$newDatabase->uuid,
                        str_starts_with($originalName, 'mongodb-data-') => 'mongodb-data-'.$newDatabase->uuid,
                        str_starts_with($originalName, 'keydb-data-') => 'keydb-data-'.$newDatabase->uuid,
                        str_starts_with($originalName, 'dragonfly-data-') => 'dragonfly-data-'.$newDatabase->uuid,
                        str_starts_with($volume->name, $database->uuid) => str($volume->name)->replace($database->uuid, $newDatabase->uuid),
                        default => $newDatabase->uuid.'-'.$volume->name,
                    };

                    $newPersistentVolume = $volume->replicate([
                        'id',
                        'created_at',
                        'updated_at',
                        'uuid',
                    ])->fill([
                        'name' => $newName,
                        'resource_id' => $newDatabase->id,
                    ]);
                    $newPersistentVolume->save();

                    if ($cloneVolumeData) {
                        try {
                            StopDatabase::dispatch($database);
                            VolumeCloneJob::dispatch($volume->name, $newPersistentVolume->name, $database->destination->server, $newDatabase->destination->server, $newPersistentVolume);
                            StartDatabase::dispatch($database);
                        } catch (\Exception $e) {
                            Log::error('Failed to copy volume data for '.$volume->name.': '.$e->getMessage());
                        }
                    }
                }

                foreach ($database->fileStorages()->get() as $storage) {
                    $storage->replicate(['id', 'created_at', 'updated_at'])->fill(['resource_id' => $newDatabase->id])->save();
                }

                foreach ($database->scheduledBackups()->get() as $backup) {
                    $backup->replicate(['id', 'created_at', 'updated_at'])->fill([
                        'uuid' => (string) new Cuid2,
                        'database_id' => $newDatabase->id,
                        'database_type' => $newDatabase->getMorphClass(),
                        'team_id' => currentTeam()->id,
                    ])->save();
                }

                foreach ($database->environment_variables()->get() as $environmentVariable) {
                    $environmentVariable->replicate(['id', 'created_at', 'updated_at'])->fill([
                        'resourceable_id' => $newDatabase->id,
                        'resourceable_type' => $newDatabase->getMorphClass(),
                    ])->save();
                }
            }

            foreach ($sourceEnvironment->services as $service) {
                $newService = $service->replicate([
                    'id',
                    'created_at',
                    'updated_at',
                ])->fill([
                    'uuid' => (string) new Cuid2,
                    'environment_id' => $targetEnvironment->id,
                    'destination_id' => $validated['destination_id'],
                ]);
                $newService->save();

                foreach ($service->tags as $tag) {
                    $newService->tags()->attach($tag->id);
                }

                foreach ($service->scheduled_tasks()->get() as $task) {
                    $task->replicate(['id', 'created_at', 'updated_at'])->fill([
                        'uuid' => (string) new Cuid2,
                        'service_id' => $newService->id,
                        'team_id' => currentTeam()->id,
                    ])->save();
                }

                foreach ($service->environment_variables()->get() as $environmentVariable) {
                    $environmentVariable->replicate(['id', 'created_at', 'updated_at'])->fill([
                        'resourceable_id' => $newService->id,
                        'resourceable_type' => $newService->getMorphClass(),
                    ])->save();
                }

                foreach ($newService->applications()->get() as $application) {
                    $application->fill(['status' => 'exited'])->save();

                    foreach ($application->persistentStorages()->get() as $volume) {
                        $newName = str_starts_with($volume->name, $application->uuid)
                            ? str($volume->name)->replace($application->uuid, $application->uuid)
                            : $application->uuid.'-'.$volume->name;

                        $newPersistentVolume = $volume->replicate([
                            'id',
                            'created_at',
                            'updated_at',
                            'uuid',
                        ])->fill(['name' => $newName, 'resource_id' => $application->id]);
                        $newPersistentVolume->save();

                        if ($cloneVolumeData) {
                            try {
                                StopService::dispatch($application);
                                VolumeCloneJob::dispatch($volume->name, $newPersistentVolume->name, $application->service->destination->server, $newService->destination->server, $newPersistentVolume);
                                StartService::dispatch($application);
                            } catch (\Exception $e) {
                                Log::error('Failed to copy volume data for '.$volume->name.': '.$e->getMessage());
                            }
                        }
                    }

                    foreach ($application->fileStorages()->get() as $storage) {
                        $storage->replicate(['id', 'created_at', 'updated_at'])->fill(['resource_id' => $application->id])->save();
                    }
                }

                foreach ($newService->databases()->get() as $database) {
                    $database->fill(['status' => 'exited'])->save();

                    foreach ($database->persistentStorages()->get() as $volume) {
                        $newName = str_starts_with($volume->name, $database->uuid)
                            ? str($volume->name)->replace($database->uuid, $database->uuid)
                            : $database->uuid.'-'.$volume->name;

                        $newPersistentVolume = $volume->replicate([
                            'id',
                            'created_at',
                            'updated_at',
                            'uuid',
                        ])->fill(['name' => $newName, 'resource_id' => $database->id]);
                        $newPersistentVolume->save();

                        if ($cloneVolumeData) {
                            try {
                                StopService::dispatch($database->service);
                                VolumeCloneJob::dispatch($volume->name, $newPersistentVolume->name, $database->service->destination->server, $newService->destination->server, $newPersistentVolume);
                                StartService::dispatch($database->service);
                            } catch (\Exception $e) {
                                Log::error('Failed to copy volume data for '.$volume->name.': '.$e->getMessage());
                            }
                        }
                    }

                    foreach ($database->fileStorages()->get() as $storage) {
                        $storage->replicate(['id', 'created_at', 'updated_at'])->fill(['resource_id' => $database->id])->save();
                    }

                    foreach ($database->scheduledBackups()->get() as $backup) {
                        $backup->replicate(['id', 'created_at', 'updated_at'])->fill([
                            'uuid' => (string) new Cuid2,
                            'database_id' => $database->id,
                            'database_type' => $database->getMorphClass(),
                            'team_id' => currentTeam()->id,
                        ])->save();
                    }
                }

                $newService->parse();
            }
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('project.resource.index', [
            'project_uuid' => $targetProject->uuid,
            'environment_uuid' => $targetEnvironment->uuid,
        ]);
    }
}
