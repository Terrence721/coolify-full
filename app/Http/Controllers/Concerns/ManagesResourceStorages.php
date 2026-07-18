<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Application;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDatabaseInstance;
use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * React port of the persistent-storage tab family — App\Livewire\Project\Service\Storage
 * (the tabbed volumes/files/directories layout + add modals), Project\Shared\Storages\{All,Show}
 * (volume cards), and Project\Service\FileStorage (file/directory mount cards) — standalone
 * databases and services (Phase 57), joined by Application (Phase 65), which shares the same
 * "single resource, full Add dropdown" shape databases get (`Project\Service\Storage` binds
 * generically to any non-service resource, Application included — the class name is a
 * historical artifact, not a scoping signal).
 *
 * Resource shapes, faithful to the original blade branches:
 * - A standalone database or Application gets the full UI: Add dropdown (volume/file/directory
 *   mounts) plus editable volume cards and file-mount cards. `configurationDir()` picks the
 *   right base path per type (`application_configuration_dir()` vs `database_configuration_dir()`),
 *   and `requiresHostPath()` ports the original's Application-only swarm rule: a swarm
 *   destination makes the volume's host path required instead of optional (no such rule exists
 *   for databases in the original, even though they can also target a swarm destination).
 * - A service renders one read-mostly section per child (ServiceApplication/ServiceDatabase):
 *   volumes are read-only (defined by the compose file), file/directory mounts remain
 *   editable/loadable/convertible/deletable.
 *
 * The file-mount server operations (save/load/delete content over SSH) follow the original's
 * semantics: content updates save the model first, then push to the server, rolling the model
 * back if the push fails. These SSH paths carry the same untested-happy-path gap as every other
 * SSH-touching conversion — see docs/smoketest.md.
 */
trait ManagesResourceStorages
{
    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function storagesTabProps(Application|Service|StandaloneDatabaseInstance $resource, array $parameters, string $routePrefix): array
    {
        $isService = $resource instanceof Service;
        $sourceDirPlaceholder = null;
        $storageUrls = null;

        if ($resource instanceof Service) {
            $children = $resource->applications()->get()->concat($resource->databases()->get());
            $sections = $children->map(fn (ServiceApplication|ServiceDatabase $child) => $this->storageSectionProps($child, $parameters, $routePrefix, isService: true))->values();
        } else {
            $sections = collect([$this->storageSectionProps($resource, $parameters, $routePrefix, isService: false)]);
            $sourceDirPlaceholder = $this->configurationDir($resource)."/{$resource->uuid}";
            $storageUrls = [
                'volumeStore' => route("{$routePrefix}.storages.volume.store", $parameters),
                'fileStore' => route("{$routePrefix}.storages.file.store", $parameters),
                'directoryStore' => route("{$routePrefix}.storages.directory.store", $parameters),
            ];
        }

        return [
            'sections' => $sections,
            'isService' => $isService,
            'canAddMounts' => ! $isService,
            'canUpdate' => auth()->user()->can('update', $resource),
            'sourceDirPlaceholder' => $sourceDirPlaceholder,
            'storageUrls' => $storageUrls,
        ];
    }

    public function storeStorageVolume(Request $request, Application|StandaloneDatabaseInstance $resource): RedirectResponse
    {
        $this->authorize('update', $resource);

        $validated = Validator::make($request->all(), [
            'name' => ValidationPatterns::volumeNameRules(),
            'mount_path' => 'required|string',
            'host_path' => [
                $this->requiresHostPath($resource) ? 'required' : 'nullable',
                'string',
                'regex:'.ValidationPatterns::DIRECTORY_PATH_PATTERN,
            ],
        ], array_merge(ValidationPatterns::volumeNameMessages(), [
            'host_path.regex' => 'Host path must start with / and only contain safe path characters.',
        ]))->validate();

        LocalPersistentVolume::create([
            'name' => $resource->uuid.'-'.$validated['name'],
            'mount_path' => $validated['mount_path'],
            'host_path' => $validated['host_path'] ?? null,
            'resource_id' => $resource->id,
            'resource_type' => $resource->getMorphClass(),
        ]);

        return back()->with('success', 'Volume added successfully');
    }

    public function storeStorageFile(Request $request, Application|StandaloneDatabaseInstance $resource): RedirectResponse
    {
        $this->authorize('update', $resource);

        $validated = Validator::make($request->all(), [
            'file_storage_path' => 'required|string',
            'file_storage_content' => 'nullable|string',
        ])->validate();

        $path = str(trim($validated['file_storage_path']))->start('/')->value();

        try {
            validateShellSafePath($path, 'file storage path');
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in storeStorageFile().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }

        LocalFileVolume::create([
            'fs_path' => $this->configurationDir($resource).'/'.$resource->uuid.$path,
            'mount_path' => $path,
            'content' => $validated['file_storage_content'] ?? null,
            'is_directory' => false,
            'resource_id' => $resource->id,
            'resource_type' => $resource->getMorphClass(),
        ]);

        return back()->with('success', 'File mount added successfully');
    }

    public function storeStorageDirectory(Request $request, Application|StandaloneDatabaseInstance $resource): RedirectResponse
    {
        $this->authorize('update', $resource);

        $validated = Validator::make($request->all(), [
            'source' => 'required|string',
            'destination' => 'required|string',
        ])->validate();

        $source = str(trim($validated['source']))->start('/')->value();
        $destination = str(trim($validated['destination']))->start('/')->value();

        try {
            validateShellSafePath($source, 'storage source path');
            validateShellSafePath($destination, 'storage destination path');
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in storeStorageDirectory().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }

        LocalFileVolume::create([
            'fs_path' => $source,
            'mount_path' => $destination,
            'is_directory' => true,
            'resource_id' => $resource->id,
            'resource_type' => $resource->getMorphClass(),
        ]);

        return back()->with('success', 'Directory mount added successfully');
    }

    public function updateStorageVolume(Request $request, Application|Service|StandaloneDatabaseInstance $resource, LocalPersistentVolume $volume): RedirectResponse
    {
        $this->authorize('update', $resource);

        if ($volume->shouldBeReadOnlyInUI()) {
            return back()->with('error', 'This volume is read-only and cannot be modified from the UI.');
        }

        $validated = Validator::make($request->all(), [
            'name' => ValidationPatterns::volumeNameRules(),
            'mount_path' => ['required', 'string', 'regex:'.ValidationPatterns::DIRECTORY_PATH_PATTERN],
            'host_path' => ['nullable', 'string', 'regex:'.ValidationPatterns::DIRECTORY_PATH_PATTERN],
        ], array_merge(ValidationPatterns::volumeNameMessages(), [
            'mount_path.regex' => 'Mount path must start with / and only contain safe path characters.',
            'host_path.regex' => 'Host path must start with / and only contain safe path characters.',
        ]))->validate();

        $volume->update([
            'name' => $validated['name'],
            'mount_path' => $validated['mount_path'],
            'host_path' => $validated['host_path'] ?? null,
        ]);

        return back()->with('success', 'Storage updated successfully');
    }

    public function destroyStorageVolume(Request $request, Application|Service|StandaloneDatabaseInstance $resource, LocalPersistentVolume $volume): RedirectResponse
    {
        $this->authorize('update', $resource);

        $validated = Validator::make($request->all(), [
            'password' => 'required|string',
        ])->validate();

        if (! verifyPasswordConfirmation($validated['password'])) {
            return back()->with('error', 'The provided password is incorrect.');
        }

        $volume->delete();

        return back()->with('success', 'Storage deleted.');
    }

    public function updateStorageFile(Request $request, Application|Service|StandaloneDatabaseInstance $resource, LocalFileVolume $file): RedirectResponse
    {
        $this->authorize('update', $resource);

        if ($file->is_too_large) {
            return back()->with('error', 'File on server is too large to edit from the UI.');
        }

        $validated = Validator::make($request->all(), [
            'content' => 'nullable|string',
        ])->validate();

        $original = $file->getOriginal();
        try {
            $file->content = $file->is_directory ? null : ($validated['content'] ?? null);
            $file->save();
            $file->saveStorageOnServer();
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in updateStorageFile().', ['error' => $e->getMessage()]);
            $file->setRawAttributes($original);
            $file->save();

            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'File updated.');
    }

    public function loadStorageFile(Application|Service|StandaloneDatabaseInstance $resource, LocalFileVolume $file): RedirectResponse
    {
        $this->authorize('view', $resource);

        try {
            $file->loadStorageOnServer();
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in loadStorageFile().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'File storage loaded from server.');
    }

    public function convertStorageFile(Application|Service|StandaloneDatabaseInstance $resource, LocalFileVolume $file): RedirectResponse
    {
        $this->authorize('update', $resource);

        try {
            $file->deleteStorageOnServer();
            $file->is_directory = ! $file->is_directory;
            $file->content = null;
            $file->is_based_on_git = false;
            $file->save();
            $file->saveStorageOnServer();
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in convertStorageFile().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $file->is_directory ? 'Converted to directory.' : 'Converted to file.');
    }

    public function destroyStorageFile(Request $request, Application|Service|StandaloneDatabaseInstance $resource, LocalFileVolume $file): RedirectResponse
    {
        $this->authorize('update', $resource);

        $validated = Validator::make($request->all(), [
            'password' => 'required|string',
            'permanently_delete' => 'nullable|boolean',
        ])->validate();

        if (! verifyPasswordConfirmation($validated['password'])) {
            return back()->with('error', 'The provided password is incorrect.');
        }

        $message = $file->is_directory ? 'Directory deleted.' : 'File deleted.';
        if ($request->boolean('permanently_delete')) {
            try {
                $file->deleteStorageOnServer();
                $message = $file->is_directory ? 'Directory deleted from the server.' : 'File deleted from the server.';
            } catch (\Throwable $e) {
                Log::error('Unhandled exception in destroyStorageFile().', ['error' => $e->getMessage()]);
                return back()->with('error', $e->getMessage());
            }
        }
        $file->delete();

        return back()->with('success', $message);
    }

    /**
     * A volume/file id from the client must belong to the routed resource: the database itself,
     * or (for services) one of the service's own application/database children.
     */
    private function resolveOwnedVolume(Application|Service|StandaloneDatabaseInstance $resource, string $volume_id): LocalPersistentVolume
    {
        $volume = LocalPersistentVolume::findOrFail($volume_id);
        abort_unless($this->storageBelongsToResource($volume->resource, $resource), 404);

        return $volume;
    }

    private function resolveOwnedFileVolume(Application|Service|StandaloneDatabaseInstance $resource, string $file_id): LocalFileVolume
    {
        $file = LocalFileVolume::findOrFail($file_id);
        abort_unless($this->storageBelongsToResource($file->service, $resource), 404);

        return $file;
    }

    private function storageBelongsToResource(?Model $owner, Application|Service|StandaloneDatabaseInstance $resource): bool
    {
        if ($owner === null) {
            return false;
        }
        if ($owner->is($resource)) {
            return true;
        }
        if ($resource instanceof Service) {
            return (int) data_get($owner, 'service_id') === (int) $resource->id;
        }

        return false;
    }

    private function configurationDir(Application|StandaloneDatabaseInstance $resource): string
    {
        return $resource instanceof Application ? application_configuration_dir() : database_configuration_dir();
    }

    /**
     * Port of the original Storage::mount()'s Application-only isSwarm check — a database
     * (which can also target a swarm destination) never had this rule in the original either,
     * so it stays Application-only here rather than generalized further.
     */
    private function requiresHostPath(Application|StandaloneDatabaseInstance $resource): bool
    {
        return $resource instanceof Application && (bool) $resource->destination?->server?->isSwarm();
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function storageSectionProps(Application|StandaloneDatabaseInstance|ServiceApplication|ServiceDatabase $owner, array $parameters, string $routePrefix, bool $isService): array
    {
        $volumes = $owner->persistentStorages()->get();
        $files = $owner->fileStorages()->get();
        $firstVolumeId = $volumes->sortBy('id')->first()?->id;

        return [
            'name' => $isService ? str($owner->name)->headline()->value() : null,
            'volumes' => $volumes->map(fn (LocalPersistentVolume $volume) => [
                'id' => $volume->id,
                'name' => $volume->name,
                'mountPath' => $volume->mount_path,
                'hostPath' => $volume->host_path,
                'isReadOnly' => $isService || $volume->shouldBeReadOnlyInUI(),
                'isFirst' => $volume->id === $firstVolumeId,
                'urls' => [
                    'update' => route("{$routePrefix}.storages.volume.update", [...$parameters, 'volume_id' => $volume->id]),
                    'destroy' => route("{$routePrefix}.storages.volume.destroy", [...$parameters, 'volume_id' => $volume->id]),
                ],
            ])->values(),
            'files' => $files->map(fn (LocalFileVolume $file) => [
                'id' => $file->id,
                'fsPath' => $file->fs_path,
                'mountPath' => $file->mount_path,
                'content' => strlen((string) $file->content) > LocalFileVolume::MAX_CONTENT_SIZE
                    ? LocalFileVolume::TOO_LARGE_PLACEHOLDER
                    : $file->content,
                'isDirectory' => (bool) $file->is_directory,
                'isReadOnly' => $file->shouldBeReadOnlyInUI() || (bool) $file->is_too_large,
                'isTooLarge' => (bool) $file->is_too_large,
                'isBinary' => (bool) $file->is_binary,
                'urls' => [
                    'update' => route("{$routePrefix}.storages.file.update", [...$parameters, 'file_id' => $file->id]),
                    'load' => route("{$routePrefix}.storages.file.load", [...$parameters, 'file_id' => $file->id]),
                    'convert' => route("{$routePrefix}.storages.file.convert", [...$parameters, 'file_id' => $file->id]),
                    'destroy' => route("{$routePrefix}.storages.file.destroy", [...$parameters, 'file_id' => $file->id]),
                ],
            ])->values(),
        ];
    }
}
