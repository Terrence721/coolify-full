<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Application;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Service;
use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * JSON/API port of the storages() family shared by ApplicationsController,
 * DatabasesController, and ServicesController. Application/Database storages
 * are owned directly by the resource; Service has none of its own — it
 * aggregates across its ServiceApplication/ServiceDatabase children (tagging
 * each row with resource_uuid/resource_type) and fans out any lookup across
 * both child collections. Persistent storages owned by a service child are
 * unconditionally read-only (shouldBeReadOnlyInUI() -> isServiceResource()),
 * so only is_preview_suffix_enabled and file storages remain mutable there.
 *
 * Team-scoped resource resolution and $this->authorize() stay in each
 * controller — only the post-resolution body lives here.
 */
trait ManagesApiResourceStorages
{
    /**
     * @return Collection<int, mixed>
     */
    private function ensureStorageCollection(mixed $value): Collection
    {
        if ($value instanceof Collection) {
            return $value;
        }

        if (is_array($value)) {
            return collect($value);
        }

        return collect();
    }

    /**
     * @return array{persistent_storages: Collection<int, mixed>, file_storages: Collection<int, mixed>}
     */
    private function apiStoragesPayload(Model $resource): array
    {
        if ($resource instanceof Service) {
            $persistentStorages = collect();
            $fileStorages = collect();

            foreach ($resource->applications as $app) {
                $appUuid = (string) data_get($app, 'uuid');
                $persistentStorages = $persistentStorages->merge(
                    $app->persistentStorages()->get()->map(fn ($s) => $s->setAttribute('resource_uuid', $appUuid)->setAttribute('resource_type', 'application'))
                );
                $fileStorages = $fileStorages->merge(
                    $app->fileStorages()->get()->map(fn ($s) => $s->setAttribute('resource_uuid', $appUuid)->setAttribute('resource_type', 'application'))
                );
            }
            foreach ($resource->databases as $db) {
                $dbUuid = (string) data_get($db, 'uuid');
                $persistentStorages = $persistentStorages->merge(
                    $db->persistentStorages()->get()->map(fn ($s) => $s->setAttribute('resource_uuid', $dbUuid)->setAttribute('resource_type', 'database'))
                );
                $fileStorages = $fileStorages->merge(
                    $db->fileStorages()->get()->map(fn ($s) => $s->setAttribute('resource_uuid', $dbUuid)->setAttribute('resource_type', 'database'))
                );
            }

            return [
                'persistent_storages' => $persistentStorages->sortBy('id')->values(),
                'file_storages' => $fileStorages->sortBy('id')->values(),
            ];
        }

        return [
            'persistent_storages' => $this->ensureStorageCollection(data_get($resource, 'persistentStorages'))->sortBy('id')->values(),
            'file_storages' => $this->ensureStorageCollection(data_get($resource, 'fileStorages'))->sortBy('id')->values(),
        ];
    }

    /**
     * Resolves the model that a new/looked-up storage's resource_id/resource_type point
     * at: the resource itself for Application/Database, or the named child (found via a
     * `resource_uuid` request field) for Service.
     */
    private function resolveApiStorageOwner(Model $resource, ?string $requestedResourceUuid): ?Model
    {
        if (! $resource instanceof Service) {
            return $resource;
        }

        $owner = $resource->applications()->where('uuid', $requestedResourceUuid)->first();
        if (! $owner) {
            $owner = $resource->databases()->where('uuid', $requestedResourceUuid)->first();
        }

        return $owner;
    }

    private function apiStorageConfigurationDir(Model $resource): string
    {
        if ($resource instanceof Application) {
            return application_configuration_dir();
        }
        if ($resource instanceof Service) {
            return service_configuration_dir();
        }

        return database_configuration_dir();
    }

    /**
     * Locates a single storage by uuid-or-id + type. Direct property lookup for
     * Application/Database; a 2-level fan-out (applications then databases) for Service,
     * since it owns no storages of its own.
     */
    private function findApiStorageByLookup(Model $resource, string $type, string $lookupField, mixed $lookupValue): LocalPersistentVolume|LocalFileVolume|null
    {
        if (! $resource instanceof Service) {
            $storages = $type === 'persistent'
                ? data_get($resource, 'persistentStorages', collect())
                : data_get($resource, 'fileStorages', collect());

            return $this->ensureStorageCollection($storages)->where($lookupField, $lookupValue)->first();
        }

        foreach ($resource->applications as $app) {
            $storage = $type === 'persistent'
                ? $app->persistentStorages()->get()->where($lookupField, $lookupValue)->first()
                : $app->fileStorages()->get()->where($lookupField, $lookupValue)->first();
            if ($storage) {
                return $storage;
            }
        }
        foreach ($resource->databases as $db) {
            $storage = $type === 'persistent'
                ? $db->persistentStorages()->get()->where($lookupField, $lookupValue)->first()
                : $db->fileStorages()->get()->where($lookupField, $lookupValue)->first();
            if ($storage) {
                return $storage;
            }
        }

        return null;
    }

    /**
     * Locates a single storage by uuid only, persistent-then-file, with the same
     * application-then-database fan-out for Service.
     */
    private function findApiStorageByUuid(Model $resource, string $storageUuid): LocalPersistentVolume|LocalFileVolume|null
    {
        if (! $resource instanceof Service) {
            return $this->ensureStorageCollection(data_get($resource, 'persistentStorages'))->where('uuid', $storageUuid)->first()
                ?? $this->ensureStorageCollection(data_get($resource, 'fileStorages'))->where('uuid', $storageUuid)->first();
        }

        foreach ($resource->applications as $app) {
            if ($storage = $app->persistentStorages()->get()->where('uuid', $storageUuid)->first()) {
                return $storage;
            }
        }
        foreach ($resource->databases as $db) {
            if ($storage = $db->persistentStorages()->get()->where('uuid', $storageUuid)->first()) {
                return $storage;
            }
        }
        foreach ($resource->applications as $app) {
            if ($storage = $app->fileStorages()->get()->where('uuid', $storageUuid)->first()) {
                return $storage;
            }
        }
        foreach ($resource->databases as $db) {
            if ($storage = $db->fileStorages()->get()->where('uuid', $storageUuid)->first()) {
                return $storage;
            }
        }

        return null;
    }

    /**
     * Validates an update_storage() request and locates the target storage — identical
     * across all three controllers (unlike create_storage(), update never needs to resolve
     * which service child owns the storage; findApiStorageByLookup()'s fan-out finds it
     * directly). Returns either the located Model or an error JsonResponse to return as-is.
     */
    private function resolveApiStorageForUpdate(Request $request, Model $resource): LocalPersistentVolume|LocalFileVolume|JsonResponse
    {
        $validator = customApiValidator($request->all(), [
            'uuid' => 'string',
            'id' => 'integer',
            'type' => 'required|string|in:persistent,file',
            'is_preview_suffix_enabled' => 'boolean',
            'name' => ['string', 'regex:'.ValidationPatterns::VOLUME_NAME_PATTERN],
            'mount_path' => 'string',
            'host_path' => ['string', 'nullable', 'regex:'.ValidationPatterns::DIRECTORY_PATH_PATTERN],
            'content' => 'string|nullable',
        ]);

        $allAllowedFields = ['uuid', 'id', 'type', 'is_preview_suffix_enabled', 'name', 'mount_path', 'host_path', 'content'];
        $extraFields = array_diff(array_keys($request->all()), $allAllowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $storageUuid = $request->input('uuid');
        $storageId = $request->input('id');

        if (! $storageUuid && ! $storageId) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['uuid' => 'Either uuid or id is required.'],
            ], 422);
        }

        $lookupField = $storageUuid ? 'uuid' : 'id';
        $lookupValue = $storageUuid ?? $storageId;

        $storage = $this->findApiStorageByLookup($resource, $request->type, $lookupField, $lookupValue);

        if (! $storage) {
            return response()->json([
                'message' => 'Storage not found.',
            ], 404);
        }

        return $storage;
    }

    /**
     * @param  array{event: string, resourceKey: string}  $auditContext
     */
    private function applyApiStorageCreate(Request $request, Model $owner, Model $topLevelResource, array $auditContext, string $teamId, string $resourceUuid): JsonResponse
    {
        $validator = customApiValidator($request->all(), [
            'type' => 'required|string|in:persistent,file',
            'name' => ['string', 'regex:'.ValidationPatterns::VOLUME_NAME_PATTERN],
            'mount_path' => 'required|string',
            'host_path' => ['string', 'nullable', 'regex:'.ValidationPatterns::DIRECTORY_PATH_PATTERN],
            'content' => 'string|nullable',
            'is_directory' => 'boolean',
            'fs_path' => 'string',
        ]);

        $allAllowedFields = ['type', 'name', 'mount_path', 'host_path', 'content', 'is_directory', 'fs_path'];
        $extraFields = array_diff(array_keys($request->all()), $allAllowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        if ($request->type === 'persistent') {
            if (! $request->name) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['name' => 'The name field is required for persistent storages.'],
                ], 422);
            }

            $typeSpecificInvalidFields = array_intersect(['content', 'is_directory', 'fs_path'], array_keys($request->all()));
            if (! empty($typeSpecificInvalidFields)) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => collect($typeSpecificInvalidFields)
                        ->mapWithKeys(fn ($field) => [$field => "Field '{$field}' is not valid for type 'persistent'."]),
                ], 422);
            }

            $ownerUuid = (string) data_get($owner, 'uuid');
            $ownerId = (int) data_get($owner, 'id');

            $storage = LocalPersistentVolume::create([
                'name' => $ownerUuid.'-'.$request->name,
                'mount_path' => $request->mount_path,
                'host_path' => $request->host_path,
                'resource_id' => $ownerId,
                'resource_type' => $owner->getMorphClass(),
            ]);

            return response()->json($storage, 201);
        }

        $typeSpecificInvalidFields = array_intersect(['name', 'host_path'], array_keys($request->all()));
        if (! empty($typeSpecificInvalidFields)) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => collect($typeSpecificInvalidFields)
                    ->mapWithKeys(fn ($field) => [$field => "Field '{$field}' is not valid for type 'file'."]),
            ], 422);
        }

        $isDirectory = $request->boolean('is_directory', false);

        if ($isDirectory) {
            if (! $request->fs_path) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['fs_path' => 'The fs_path field is required for directory mounts.'],
                ], 422);
            }

            $fsPath = str($request->fs_path)->trim()->start('/')->value();
            $mountPath = str($request->mount_path)->trim()->start('/')->value();

            validateShellSafePath($fsPath, 'storage source path');
            validateShellSafePath($mountPath, 'storage destination path');

            $storage = LocalFileVolume::create([
                'fs_path' => $fsPath,
                'mount_path' => $mountPath,
                'is_directory' => true,
                'resource_id' => $owner->id,
                'resource_type' => $owner->getMorphClass(),
            ]);
        } else {
            $mountPath = str($request->mount_path)->trim()->start('/')->value();

            validateShellSafePath($mountPath, 'file storage path');

            $topLevelResourceUuid = (string) data_get($topLevelResource, 'uuid');
            $ownerId = (int) data_get($owner, 'id');
            $fsPath = $this->apiStorageConfigurationDir($topLevelResource).'/'.$topLevelResourceUuid.$mountPath;

            $storage = LocalFileVolume::create([
                'fs_path' => $fsPath,
                'mount_path' => $mountPath,
                'content' => $request->content,
                'is_directory' => false,
                'resource_id' => $ownerId,
                'resource_type' => $owner->getMorphClass(),
            ]);
        }

        auditLog($auditContext['event'].'_created', [
            'team_id' => $teamId,
            $auditContext['resourceKey'] => $resourceUuid,
            'storage_uuid' => $storage->uuid ?? null,
            'storage_id' => $storage->id,
            'storage_type' => $request->type,
            'mount_path' => $storage->mount_path,
        ]);

        return response()->json($storage, 201);
    }

    /**
     * @param  array{event: string, resourceKey: string}  $auditContext
     */
    private function applyApiStorageUpdate(Request $request, LocalPersistentVolume|LocalFileVolume $storage, array $auditContext, string $teamId, string $resourceUuid): JsonResponse
    {
        $isReadOnly = $storage->shouldBeReadOnlyInUI();
        $editableOnlyFields = ['name', 'mount_path', 'host_path', 'content'];
        $requestedEditableFields = array_intersect($editableOnlyFields, array_keys($request->all()));

        if ($isReadOnly && ! empty($requestedEditableFields)) {
            return response()->json([
                'message' => 'This storage is read-only (managed by docker-compose or service definition). Only is_preview_suffix_enabled can be updated.',
                'read_only_fields' => array_values($requestedEditableFields),
            ], 422);
        }

        if (! $isReadOnly) {
            $typeSpecificInvalidFields = $request->type === 'persistent'
                ? array_intersect(['content'], array_keys($request->all()))
                : array_intersect(['name', 'host_path'], array_keys($request->all()));

            if (! empty($typeSpecificInvalidFields)) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => collect($typeSpecificInvalidFields)
                        ->mapWithKeys(fn ($field) => [$field => "Field '{$field}' is not valid for type '{$request->type}'."]),
                ], 422);
            }
        }

        if ($request->has('is_preview_suffix_enabled')) {
            $storage->is_preview_suffix_enabled = $request->is_preview_suffix_enabled;
        }

        if (! $isReadOnly) {
            if ($request->type === 'persistent') {
                if ($request->has('name')) {
                    $storage->name = $request->name;
                }
                if ($request->has('mount_path')) {
                    $storage->mount_path = $request->mount_path;
                }
                if ($request->has('host_path')) {
                    $storage->host_path = $request->host_path;
                }
            } else {
                if ($request->has('mount_path')) {
                    $storage->mount_path = $request->mount_path;
                }
                if ($request->has('content')) {
                    $storage->content = $request->content;
                }
            }
        }

        $storage->save();

        auditLog($auditContext['event'].'_updated', [
            'team_id' => $teamId,
            $auditContext['resourceKey'] => $resourceUuid,
            'storage_uuid' => $storage->uuid ?? null,
            'storage_id' => $storage->id,
            'storage_type' => $request->type,
            'mount_path' => $storage->mount_path ?? null,
        ]);

        return response()->json($storage);
    }

    /**
     * @param  array{event: string, resourceKey: string}  $auditContext
     */
    private function applyApiStorageDelete(LocalPersistentVolume|LocalFileVolume $storage, array $auditContext, string $teamId, string $resourceUuid, string $storageUuid): JsonResponse
    {
        if ($storage->shouldBeReadOnlyInUI()) {
            return response()->json([
                'message' => 'This storage is read-only (managed by docker-compose or service definition) and cannot be deleted.',
            ], 422);
        }

        if ($storage instanceof LocalFileVolume) {
            $storage->deleteStorageOnServer();
        }

        $storageType = $storage instanceof LocalFileVolume ? 'file' : 'persistent';
        $storageMountPath = $storage->mount_path ?? null;
        $storage->delete();

        auditLog($auditContext['event'].'_deleted', [
            'team_id' => $teamId,
            $auditContext['resourceKey'] => $resourceUuid,
            'storage_uuid' => $storageUuid,
            'storage_type' => $storageType,
            'mount_path' => $storageMountPath,
        ]);

        return response()->json(['message' => 'Storage deleted.']);
    }
}
