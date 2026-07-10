<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ServiceDatabase;
use App\Rules\SafeWebhookUrl;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class StorageController extends Controller
{
    use AuthorizesRequests;

    public function index(): Response
    {
        $storages = S3Storage::ownedByCurrentTeam()->get();

        return Inertia::render('Storage/Index', [
            'storages' => $storages->map(fn (S3Storage $storage) => [
                'uuid' => $storage->uuid,
                'name' => $storage->name,
                'description' => $storage->description,
                'isUsable' => $storage->is_usable,
                'showUrl' => route('storage.show', ['storage_uuid' => $storage->uuid]),
            ]),
            'canCreate' => auth()->user()?->can('create', S3Storage::class) ?? false,
            'createUrl' => route('storage.store'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', S3Storage::class);

        $validated = Validator::make(
            $request->all(),
            [
                'name' => ValidationPatterns::nameRules(),
                'description' => ValidationPatterns::descriptionRules(),
                'region' => ['required', 'max:255'],
                'key' => ['required', 'max:255'],
                'secret' => ['required', 'max:255'],
                'bucket' => ['required', 'max:255'],
                'endpoint' => ['nullable', 'max:255', new SafeWebhookUrl],
            ],
            ValidationPatterns::combinedMessages(),
            [
                'region' => 'Region',
                'key' => 'Access Key',
                'secret' => 'Secret Key',
                'bucket' => 'Bucket',
                'endpoint' => 'Endpoint',
            ],
        )->validate();

        $storage = new S3Storage;
        $storage->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'region' => $validated['region'],
            'key' => $validated['key'],
            'secret' => $validated['secret'],
            'bucket' => $validated['bucket'],
            'endpoint' => empty($validated['endpoint']) ? "https://s3.{$validated['region']}.amazonaws.com" : $validated['endpoint'],
            'team_id' => currentTeam()->id,
        ]);

        try {
            $storage->testConnection();
            $storage->save();
        } catch (\Throwable $e) {
            return back()->withErrors(['endpoint' => 'Failed to create storage: '.$e->getMessage()])->withInput();
        }

        return redirect()->route('storage.show', ['storage_uuid' => $storage->uuid]);
    }

    public function show(string $storage_uuid): Response
    {
        $storage = S3Storage::ownedByCurrentTeam()->whereUuid($storage_uuid)->firstOrFail();
        $this->authorize('view', $storage);

        $backupCount = ScheduledDatabaseBackup::where('s3_storage_id', $storage->id)->count();

        return Inertia::render('Storage/Show', [
            'storage' => [
                'uuid' => $storage->uuid,
                'name' => $storage->name,
                'description' => $storage->description,
                'endpoint' => $storage->endpoint,
                'bucket' => $storage->bucket,
                'region' => $storage->region,
                'key' => $storage->key,
                'secret' => $storage->secret,
                'isUsable' => $storage->is_usable,
            ],
            'backupCount' => $backupCount,
            'canUpdate' => auth()->user()?->can('update', $storage) ?? false,
            'canDelete' => auth()->user()?->can('delete', $storage) ?? false,
            'canValidateConnection' => auth()->user()?->can('validateConnection', $storage) ?? false,
            'showUrl' => route('storage.show', ['storage_uuid' => $storage->uuid]),
            'resourcesUrl' => route('storage.resources', ['storage_uuid' => $storage->uuid]),
            'updateUrl' => route('storage.update', ['storage_uuid' => $storage->uuid]),
            'testConnectionUrl' => route('storage.test-connection', ['storage_uuid' => $storage->uuid]),
            'deleteUrl' => route('storage.destroy', ['storage_uuid' => $storage->uuid]),
        ]);
    }

    public function update(Request $request, string $storage_uuid): RedirectResponse
    {
        $storage = S3Storage::ownedByCurrentTeam()->whereUuid($storage_uuid)->firstOrFail();
        $this->authorize('update', $storage);

        $validated = Validator::make(
            $request->all(),
            [
                'name' => ValidationPatterns::nameRules(required: false),
                'description' => ValidationPatterns::descriptionRules(),
                'region' => ['required', 'max:255'],
                'key' => ['required', 'max:255'],
                'secret' => ['required', 'max:255'],
                'bucket' => ['required', 'max:255'],
                'endpoint' => ['required', 'max:255', new SafeWebhookUrl],
            ],
            ValidationPatterns::combinedMessages(),
            [
                'region' => 'Region',
                'key' => 'Access Key',
                'secret' => 'Secret Key',
                'bucket' => 'Bucket',
                'endpoint' => 'Endpoint',
            ],
        )->validate();

        try {
            DB::transaction(function () use ($storage, $validated) {
                $storage->fill([
                    'name' => $validated['name'] ?? null,
                    'description' => $validated['description'] ?? null,
                    'endpoint' => $validated['endpoint'],
                    'bucket' => $validated['bucket'],
                    'region' => $validated['region'],
                    'key' => $validated['key'],
                    'secret' => $validated['secret'],
                ]);
                $storage->save();

                $storage->testConnection(shouldSave: false);

                $storage->is_usable = true;
                $storage->unusable_email_sent = false;
                $storage->save();
            });
        } catch (\Throwable $e) {
            return back()->withErrors(['endpoint' => $e->getMessage()])->withInput();
        }

        return back()->with('success', 'Storage settings updated and connection verified.');
    }

    public function testConnection(string $storage_uuid): RedirectResponse
    {
        $storage = S3Storage::ownedByCurrentTeam()->whereUuid($storage_uuid)->firstOrFail();
        $this->authorize('validateConnection', $storage);

        try {
            $storage->testConnection(shouldSave: true);

            return back()->with('success', 'Connection is working. Tested with "ListObjectsV2" action.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to test connection: '.$e->getMessage());
        }
    }

    public function destroy(string $storage_uuid): RedirectResponse
    {
        $storage = S3Storage::ownedByCurrentTeam()->whereUuid($storage_uuid)->firstOrFail();
        $this->authorize('delete', $storage);

        $storage->delete();

        return redirect()->route('storage.index');
    }

    public function resources(string $storage_uuid): Response
    {
        $storage = S3Storage::ownedByCurrentTeam()->whereUuid($storage_uuid)->firstOrFail();
        $this->authorize('view', $storage);

        $backups = ScheduledDatabaseBackup::where('s3_storage_id', $storage->id)
            ->where('save_s3', true)
            ->with('database')
            ->get()
            ->groupBy(fn (ScheduledDatabaseBackup $backup) => $backup->database_type.'-'.$backup->database_id);

        $allStorages = S3Storage::where('team_id', $storage->team_id)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'is_usable']);

        $rows = [];
        foreach ($backups as $group) {
            $database = $group->first()->database;
            $databaseName = $database?->name ?? 'Deleted database';
            $resourceLink = null;
            $backupParams = null;

            if ($database instanceof ServiceDatabase) {
                $service = $database->service;
                $environment = $service?->environment;
                $project = $environment?->project;
                if ($service && $project && $environment) {
                    $resourceLink = route('project.service.configuration', [
                        'project_uuid' => $project->uuid,
                        'environment_uuid' => $environment->uuid,
                        'service_uuid' => $service->uuid,
                    ]);
                }
            } elseif ($database) {
                $environment = $database->environment;
                $project = $environment?->project;
                if ($project && $environment) {
                    $backupParams = [
                        'project_uuid' => $project->uuid,
                        'environment_uuid' => $environment->uuid,
                        'database_uuid' => $database->uuid,
                    ];
                    $resourceLink = route('project.database.backup.index', $backupParams);
                }
            }

            foreach ($group as $backup) {
                $rows[] = [
                    'id' => $backup->id,
                    'databaseName' => $databaseName,
                    'resourceLink' => $resourceLink,
                    'frequency' => $backup->frequency,
                    'backupLink' => $backupParams
                        ? route('project.database.backup.execution', array_merge($backupParams, ['backup_uuid' => $backup->uuid]))
                        : null,
                    'enabled' => $backup->enabled,
                    'disableS3Url' => route('storage.resources.disable-s3', ['storage_uuid' => $storage->uuid, 'backup_id' => $backup->id]),
                    'moveBackupUrl' => route('storage.resources.move-backup', ['storage_uuid' => $storage->uuid, 'backup_id' => $backup->id]),
                ];
            }
        }

        return Inertia::render('Storage/Resources', [
            'storage' => [
                'id' => $storage->id,
                'uuid' => $storage->uuid,
                'name' => $storage->name,
                'isUsable' => $storage->is_usable,
            ],
            'backups' => $rows,
            'allStorages' => $allStorages->map(fn (S3Storage $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'isUsable' => $s->is_usable,
            ]),
            'canUpdate' => auth()->user()?->can('update', $storage) ?? false,
            'showUrl' => route('storage.show', ['storage_uuid' => $storage->uuid]),
            'resourcesUrl' => route('storage.resources', ['storage_uuid' => $storage->uuid]),
        ]);
    }

    public function disableS3(string $storage_uuid, int $backup_id): RedirectResponse
    {
        $storage = S3Storage::ownedByCurrentTeam()->whereUuid($storage_uuid)->firstOrFail();
        $this->authorize('update', $storage);

        $backup = ScheduledDatabaseBackup::where('id', $backup_id)
            ->where('s3_storage_id', $storage->id)
            ->firstOrFail();

        $backup->update([
            'save_s3' => false,
            's3_storage_id' => null,
        ]);

        return back()->with('success', 'S3 disabled. S3 backup has been disabled for this schedule.');
    }

    public function moveBackup(Request $request, string $storage_uuid, int $backup_id): RedirectResponse
    {
        $storage = S3Storage::ownedByCurrentTeam()->whereUuid($storage_uuid)->firstOrFail();
        $this->authorize('update', $storage);

        $validated = Validator::make($request->all(), [
            'new_storage_id' => ['required', 'integer'],
        ])->validate();

        $backup = ScheduledDatabaseBackup::where('id', $backup_id)
            ->where('s3_storage_id', $storage->id)
            ->firstOrFail();

        if ((int) $validated['new_storage_id'] === $storage->id) {
            return back()->with('error', 'No change. The backup is already using this storage.');
        }

        $newStorage = S3Storage::where('id', $validated['new_storage_id'])
            ->where('team_id', $storage->team_id)
            ->first();

        if (! $newStorage) {
            return back()->with('error', 'Storage not found.');
        }

        $backup->update(['s3_storage_id' => $newStorage->id]);

        return back()->with('success', "Backup moved. Moved to {$newStorage->name}.");
    }
}
