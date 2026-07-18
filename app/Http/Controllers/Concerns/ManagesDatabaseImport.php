<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Contracts\StandaloneDatabaseInstance;
use App\Models\S3Storage;
use App\Models\ServiceDatabase;
use App\Support\DatabaseEngineRegistry;
use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * React port of the Import Backup tab — App\Livewire\Project\Database\{Import,ImportForm} —
 * shared by the database Configuration router and the service-database import page (Phase 61).
 *
 * Faithful-port notes:
 * - File uploads keep going to the pre-existing non-Livewire `upload.backup` chunked
 *   endpoint (UploadController); this concern only consumes the uploaded
 *   `upload/{uuid}/restore` file, exactly like ImportForm::runImport() did.
 * - The restore command base is client-editable (it was a wire:model input in the
 *   original) and executed in the database container as-is after the same
 *   password-confirmation + update-authorization gate. The dump-all command variants
 *   are served as props defaults, matching ImportForm::updatedDumpAll().
 * - S3 restore re-verifies the file exists server-side instead of trusting the
 *   client-side "Check File" state the Livewire component kept in memory.
 * - The path/bucket validators are ImportForm's own, moved verbatim.
 */
trait ManagesDatabaseImport
{
    /**
     * @param  array<string, string>  $routeParameters
     * @return array<string, mixed>
     */
    private function importTabProps(ServiceDatabase|(StandaloneDatabaseInstance&Model) $resource, string $routePrefix, array $routeParameters): array
    {
        [$container, $resourceUuid, $dbType] = $this->importResourceIdentity($resource);

        return [
            'importTab' => [
                'unsupported' => $this->importUnsupported($resource),
                'running' => str((string) ($resource->status ?? ''))->startsWith('running'),
                'resourceUuid' => $resourceUuid,
                'dbType' => $dbType,
                'commands' => $this->importCommandDefaults($dbType),
                's3Storages' => S3Storage::ownedByCurrentTeam(['id', 'name', 'description'])
                    ->where('is_usable', true)
                    ->get()
                    ->map(fn (S3Storage $s) => ['id' => $s->id, 'name' => $s->name, 'description' => $s->description])
                    ->values(),
                'canUpdate' => auth()->user()->can('update', $resource),
                'urls' => [
                    'upload' => route('upload.backup', ['databaseUuid' => $resourceUuid]),
                    'checkFile' => route("{$routePrefix}.import.check-file", $routeParameters),
                    'run' => route("{$routePrefix}.import.run", $routeParameters),
                    'checkS3' => route("{$routePrefix}.import.check-s3", $routeParameters),
                    'restoreS3' => route("{$routePrefix}.import.restore-s3", $routeParameters),
                ],
            ],
        ];
    }

    public function importCheckFile(Request $request, ServiceDatabase|(StandaloneDatabaseInstance&Model) $resource): RedirectResponse
    {
        $this->authorize('update', $resource);

        $validated = Validator::make($request->all(), [
            'customLocation' => 'required|string',
        ])->validate();

        if (! $this->validateServerPath($validated['customLocation'])) {
            return back()->with('error', 'Invalid file path. Path must be absolute and contain only safe characters (alphanumerics, dots, dashes, underscores, slashes).');
        }

        $server = $this->importServer($resource);
        $escapedPath = escapeshellarg($validated['customLocation']);
        $result = instant_remote_process(["ls -l {$escapedPath}"], $server, throwError: false);
        if (blank($result)) {
            return back()->with('error', 'The file does not exist or has been deleted.');
        }

        return back()->with('success', 'The file exists.');
    }

    public function importRun(Request $request, ServiceDatabase|(StandaloneDatabaseInstance&Model) $resource): RedirectResponse
    {
        $validated = Validator::make($request->all(), [
            'password' => 'required|string',
            'customLocation' => 'nullable|string',
            'restoreCommand' => 'required|string',
            'dumpAll' => 'boolean',
        ])->validate();

        if (! verifyPasswordConfirmation($validated['password'])) {
            return back()->with('error', 'The provided password is incorrect.');
        }
        $this->authorize('update', $resource);

        [$container, $resourceUuid, $dbType] = $this->importResourceIdentity($resource);
        if (! ValidationPatterns::isValidContainerName($container)) {
            return back()->with('error', 'Invalid container name.');
        }

        $server = $this->importServer($resource);
        $importCommands = [];
        $backupFileName = "upload/{$resourceUuid}/restore";
        $customLocation = (string) ($validated['customLocation'] ?? '');

        if (Storage::exists($backupFileName)) {
            $path = Storage::path($backupFileName);
            $tmpPath = '/tmp/'.basename($backupFileName).'_'.$resourceUuid;
            instant_scp($path, $tmpPath, $server);
            Storage::delete($backupFileName);
            $importCommands[] = "docker cp {$tmpPath} {$container}:{$tmpPath}";
        } elseif (filled($customLocation)) {
            if (! $this->validateServerPath($customLocation)) {
                return back()->with('error', 'Invalid file path. Path must be absolute and contain only safe characters.');
            }
            $tmpPath = '/tmp/restore_'.$resourceUuid;
            $escapedCustomLocation = escapeshellarg($customLocation);
            $importCommands[] = "docker cp {$escapedCustomLocation} {$container}:{$tmpPath}";
        } else {
            return back()->with('error', 'The file does not exist or has been deleted.');
        }

        $scriptPath = "/tmp/restore_{$resourceUuid}.sh";
        $restoreCommand = $this->buildImportRestoreCommand($dbType, $validated['restoreCommand'], $request->boolean('dumpAll'), $tmpPath);

        $restoreCommandBase64 = base64_encode($restoreCommand);
        $importCommands[] = "echo \"{$restoreCommandBase64}\" | base64 -d > {$scriptPath}";
        $importCommands[] = "chmod +x {$scriptPath}";
        $importCommands[] = "docker cp {$scriptPath} {$container}:{$scriptPath}";
        $importCommands[] = "docker exec {$container} sh -c '{$scriptPath}'";
        $importCommands[] = "docker exec {$container} sh -c 'echo \"Import finished with exit code $?\"'";

        $activity = remote_process($importCommands, $server, ignore_errors: true, callEventOnFinish: 'RestoreJobFinished', callEventData: [
            'scriptPath' => $scriptPath,
            'tmpPath' => $tmpPath,
            'container' => $container,
            'serverId' => $server->id,
        ]);

        return back()->with([
            'activityId' => $activity->id,
            'activityContext' => 'database-import',
        ]);
    }

    public function importCheckS3(Request $request, ServiceDatabase|(StandaloneDatabaseInstance&Model) $resource): RedirectResponse
    {
        $this->authorize('update', $resource);

        $validated = Validator::make($request->all(), [
            's3StorageId' => 'required|integer',
            's3Path' => 'required|string',
        ])->validate();

        $cleanPath = ltrim(str((string) $validated['s3Path'])->trim()->value(), '/');
        if (! $this->validateS3Path($cleanPath)) {
            return back()->with('error', 'Invalid S3 path. Path must contain only safe characters (alphanumerics, dots, dashes, underscores, slashes).');
        }

        try {
            $s3Storage = S3Storage::ownedByCurrentTeam()->findOrFail((int) $validated['s3StorageId']);
            if (! $this->validateBucketName($s3Storage->bucket)) {
                return back()->with('error', 'Invalid S3 bucket name. Bucket name must contain only alphanumerics, dots, dashes, and underscores.');
            }

            $s3Storage->testConnection();
            $disk = $this->importS3Disk($s3Storage);
            if (! $disk->exists($cleanPath)) {
                return back()->with('error', 'File not found in S3. Please check the path.');
            }

            return back()->with('success', 'File found in S3. Size: '.formatBytes($disk->size($cleanPath)));
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in importCheckS3().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }
    }

    public function importRestoreS3(Request $request, ServiceDatabase|(StandaloneDatabaseInstance&Model) $resource): RedirectResponse
    {
        $validated = Validator::make($request->all(), [
            'password' => 'required|string',
            's3StorageId' => 'required|integer',
            's3Path' => 'required|string',
            'restoreCommand' => 'required|string',
            'dumpAll' => 'boolean',
        ])->validate();

        if (! verifyPasswordConfirmation($validated['password'])) {
            return back()->with('error', 'The provided password is incorrect.');
        }
        $this->authorize('update', $resource);

        [$container, $resourceUuid, $dbType] = $this->importResourceIdentity($resource);
        if (! ValidationPatterns::isValidContainerName($container)) {
            return back()->with('error', 'Invalid container name.');
        }

        $server = $this->importServer($resource);

        try {
            $s3Storage = S3Storage::ownedByCurrentTeam()->findOrFail((int) $validated['s3StorageId']);

            if (! $this->validateBucketName($s3Storage->bucket)) {
                return back()->with('error', 'Invalid S3 bucket name. Bucket name must contain only alphanumerics, dots, dashes, and underscores.');
            }

            $cleanPath = ltrim(str((string) $validated['s3Path'])->trim()->value(), '/');
            if (! $this->validateS3Path($cleanPath)) {
                return back()->with('error', 'Invalid S3 path. Path must contain only safe characters (alphanumerics, dots, dashes, underscores, slashes).');
            }

            // The Livewire component required a prior in-memory "Check File" pass; re-verify
            // server-side instead, since the controller holds no state between requests.
            if (! $this->importS3Disk($s3Storage)->exists($cleanPath)) {
                return back()->with('error', 'File not found in S3. Please check the path.');
            }

            $helperImage = config('constants.coolify.helper_image');
            $latestVersion = getHelperVersion();
            $fullImageName = "{$helperImage}:{$latestVersion}";

            if ($resource->getMorphClass() === ServiceDatabase::class) {
                $destinationNetwork = $resource->service->destination->network ?? 'coolify';
            } else {
                $destinationNetwork = $resource->destination->network ?? 'coolify';
            }

            $containerName = "s3-restore-{$resourceUuid}";
            $helperTmpPath = '/tmp/'.basename($cleanPath);
            $serverTmpPath = "/tmp/s3-restore-{$resourceUuid}-".basename($cleanPath);
            $containerTmpPath = "/tmp/restore_{$resourceUuid}-".basename($cleanPath);
            $scriptPath = "/tmp/restore_{$resourceUuid}.sh";

            $escapedServerTmpPath = escapeshellarg($serverTmpPath);
            $escapedContainerTmpPath = escapeshellarg($containerTmpPath);
            $escapedScriptPath = escapeshellarg($scriptPath);
            $escapedHelperContainerPath = escapeshellarg("{$containerName}:{$helperTmpPath}");
            $escapedDatabaseContainerTmpPath = escapeshellarg("{$container}:{$containerTmpPath}");
            $escapedDatabaseContainerScriptPath = escapeshellarg("{$container}:{$scriptPath}");
            $restoreAndCleanupCommand = escapeshellarg("{$escapedScriptPath} && rm -f {$escapedContainerTmpPath} {$escapedScriptPath}");

            $commands = [];
            $commands[] = "docker rm -f {$containerName} 2>/dev/null || true";
            $commands[] = "rm -f {$escapedServerTmpPath} 2>/dev/null || true";
            $commands[] = "docker exec {$container} rm -f {$escapedContainerTmpPath} {$escapedScriptPath} 2>/dev/null || true";
            $commands[] = "docker run -d --network {$destinationNetwork} --name {$containerName} {$fullImageName} sleep 3600";

            $escapedEndpoint = escapeshellarg($s3Storage->endpoint);
            $escapedKey = escapeshellarg($s3Storage->key);
            $escapedSecret = escapeshellarg($s3Storage->secret);
            $commands[] = "docker exec {$containerName} mc alias set s3temp {$escapedEndpoint} {$escapedKey} {$escapedSecret}";

            $escapedS3Source = escapeshellarg("s3temp/{$s3Storage->bucket}/{$cleanPath}");
            $commands[] = "docker exec {$containerName} mc stat {$escapedS3Source}";

            $escapedHelperTmpPath = escapeshellarg($helperTmpPath);
            $commands[] = "docker exec {$containerName} mc cp {$escapedS3Source} {$escapedHelperTmpPath}";
            $commands[] = "docker cp {$escapedHelperContainerPath} {$escapedServerTmpPath}";
            $commands[] = "docker cp {$escapedServerTmpPath} {$escapedDatabaseContainerTmpPath}";
            $commands[] = "docker rm -f {$containerName} 2>/dev/null || true";
            $commands[] = "rm -f {$escapedServerTmpPath} 2>/dev/null || true";

            $restoreCommand = $this->buildImportRestoreCommand($dbType, $validated['restoreCommand'], $request->boolean('dumpAll'), $containerTmpPath);
            $restoreCommandBase64 = base64_encode($restoreCommand);
            $commands[] = "echo \"{$restoreCommandBase64}\" | base64 -d > {$escapedScriptPath}";
            $commands[] = "chmod +x {$escapedScriptPath}";
            $commands[] = "docker cp {$escapedScriptPath} {$escapedDatabaseContainerScriptPath}";
            $commands[] = "docker exec {$container} sh -c {$restoreAndCleanupCommand}";
            $commands[] = "docker exec {$container} sh -c 'echo \"Import finished with exit code $?\"'";

            $activity = remote_process($commands, $server, ignore_errors: true, callEventOnFinish: 'S3RestoreJobFinished', callEventData: [
                'containerName' => $containerName,
                'serverTmpPath' => $serverTmpPath,
                'scriptPath' => $scriptPath,
                'containerTmpPath' => $containerTmpPath,
                'container' => $container,
                'serverId' => $server->id,
            ]);

            return back()->with([
                'activityId' => $activity->id,
                'activityContext' => 'database-import',
                'info' => 'Restoring database from S3. Progress will be shown in the activity monitor...',
            ]);
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in importRestoreS3().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Container name, upload/route identity uuid, and normalized db type — the two branches
     * of ImportForm::getContainers().
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function importResourceIdentity(ServiceDatabase|(StandaloneDatabaseInstance&Model) $resource): array
    {
        if ($resource->getMorphClass() === ServiceDatabase::class) {
            $container = $resource->name.'-'.$resource->service->uuid;
            $dbTypeRaw = $resource->databaseType();
            $dbType = match (true) {
                str_contains($dbTypeRaw, 'postgres') => 'standalone-postgresql',
                str_contains($dbTypeRaw, 'mysql') => 'standalone-mysql',
                str_contains($dbTypeRaw, 'mariadb') => 'standalone-mariadb',
                str_contains($dbTypeRaw, 'mongo') => 'standalone-mongodb',
                default => $dbTypeRaw,
            };

            return [$container, $resource->uuid, $dbType];
        }

        return [$resource->uuid, $resource->uuid, $resource->type()];
    }

    private function importUnsupported(ServiceDatabase|(StandaloneDatabaseInstance&Model) $resource): bool
    {
        if ($resource instanceof StandaloneDatabaseInstance) {
            return ! DatabaseEngineRegistry::forInstance($resource)?->supportsImport;
        }

        if ($resource->getMorphClass() === ServiceDatabase::class) {
            $dbType = $resource->databaseType();
            foreach (DatabaseEngineRegistry::unsupportedImportTypes() as $type) {
                if (str_contains($dbType, $type)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function importServer(ServiceDatabase|(StandaloneDatabaseInstance&Model) $resource): \App\Models\Server
    {
        if ($resource instanceof ServiceDatabase) {
            // Service uses SoftDeletes, so this belongsTo can resolve to null at runtime (e.g. a
            // soft-deleted parent Service) even though service_id is a NOT NULL column - Larastan
            // doesn't model soft-delete query scoping.
            // @phpstan-ignore nullsafe.neverNull
            $server = $resource->service?->server;
        } else {
            $server = $resource->destination?->server;
        }
        abort_if(! $server, 404, 'Server not found for this database.');

        return $server;
    }

    /**
     * The per-engine default and dump-all command variants (ImportForm's initial
     * properties + updatedDumpAll()).
     *
     * @return array{default: string, dumpAll: string|null, dumpAllSuffix: string|null}
     */
    private function importCommandDefaults(string $dbType): array
    {
        return match ($dbType) {
            'standalone-postgresql' => [
                'default' => 'pg_restore -U $POSTGRES_USER -d ${POSTGRES_DB:-${POSTGRES_USER:-postgres}}',
                'dumpAll' => "psql -U \${POSTGRES_USER} -c \"SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname IS NOT NULL AND pid <> pg_backend_pid()\" && \\\npsql -U \${POSTGRES_USER} -t -c \"SELECT datname FROM pg_database WHERE NOT datistemplate\" | xargs -I {} dropdb -U \${POSTGRES_USER} --if-exists {} && \\\ncreatedb -U \${POSTGRES_USER} \${POSTGRES_DB:-\${POSTGRES_USER:-postgres}}",
                'dumpAllSuffix' => ' && (gunzip -cf <temp_backup_file> 2>/dev/null || cat <temp_backup_file>) | psql -U ${POSTGRES_USER} -d ${POSTGRES_DB:-${POSTGRES_USER:-postgres}}',
            ],
            'standalone-mysql' => [
                'default' => 'mysql -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE',
                'dumpAll' => "for pid in \$(mysql -u root -p\$MYSQL_ROOT_PASSWORD -N -e \"SELECT id FROM information_schema.processlist WHERE user != 'root';\"); do\n  mysql -u root -p\$MYSQL_ROOT_PASSWORD -e \"KILL \$pid\" 2>/dev/null || true\ndone && \\\nmysql -u root -p\$MYSQL_ROOT_PASSWORD -N -e \"SELECT CONCAT('DROP DATABASE IF EXISTS \\`',schema_name,'\\`;') FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema','mysql','performance_schema','sys');\" | mysql -u root -p\$MYSQL_ROOT_PASSWORD && \\\nmysql -u root -p\$MYSQL_ROOT_PASSWORD -e \"CREATE DATABASE IF NOT EXISTS \\`\${MYSQL_DATABASE:-default}\\`;\" && \\\n(gunzip -cf \$tmpPath 2>/dev/null || cat \$tmpPath) | sed -e '/^CREATE DATABASE/d' -e '/^USE \\`mysql\\`/d' | mysql -u root -p\$MYSQL_ROOT_PASSWORD \${MYSQL_DATABASE:-default}",
                'dumpAllSuffix' => ' && (gunzip -cf <temp_backup_file> 2>/dev/null || cat <temp_backup_file>) | mysql -u root -p$MYSQL_ROOT_PASSWORD ${MYSQL_DATABASE:-default}',
            ],
            'standalone-mariadb' => [
                'default' => 'mariadb -u $MARIADB_USER -p$MARIADB_PASSWORD $MARIADB_DATABASE',
                'dumpAll' => "for pid in \$(mariadb -u root -p\$MARIADB_ROOT_PASSWORD -N -e \"SELECT id FROM information_schema.processlist WHERE user != 'root';\"); do\n  mariadb -u root -p\$MARIADB_ROOT_PASSWORD -e \"KILL \$pid\" 2>/dev/null || true\ndone && \\\nmariadb -u root -p\$MARIADB_ROOT_PASSWORD -N -e \"SELECT CONCAT('DROP DATABASE IF EXISTS \\`',schema_name,'\\`;') FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema','mysql','performance_schema','sys');\" | mariadb -u root -p\$MARIADB_ROOT_PASSWORD && \\\nmariadb -u root -p\$MARIADB_ROOT_PASSWORD -e \"CREATE DATABASE IF NOT EXISTS \\`\${MARIADB_DATABASE:-default}\\`;\" && \\\n(gunzip -cf \$tmpPath 2>/dev/null || cat \$tmpPath) | sed -e '/^CREATE DATABASE/d' -e '/^USE \\`mysql\\`/d' | mariadb -u root -p\$MARIADB_ROOT_PASSWORD \${MARIADB_DATABASE:-default}",
                'dumpAllSuffix' => ' && (gunzip -cf <temp_backup_file> 2>/dev/null || cat <temp_backup_file>) | mariadb -u root -p$MARIADB_ROOT_PASSWORD ${MARIADB_DATABASE:-default}',
            ],
            'standalone-mongodb' => [
                'default' => 'mongorestore --authenticationDatabase=admin --username $MONGO_INITDB_ROOT_USERNAME --password $MONGO_INITDB_ROOT_PASSWORD --uri mongodb://localhost:27017 --gzip --archive=',
                'dumpAll' => null,
                'dumpAllSuffix' => null,
            ],
            default => ['default' => '', 'dumpAll' => null, 'dumpAllSuffix' => null],
        };
    }

    /** ImportForm::buildRestoreCommand(), with the client-editable base command as input. */
    private function buildImportRestoreCommand(string $dbType, string $baseCommand, bool $dumpAll, string $tmpPath): string
    {
        $escapedTmpPath = escapeshellarg($tmpPath);

        return match ($dbType) {
            'standalone-mariadb' => $dumpAll
                ? $baseCommand." && (gunzip -cf {$escapedTmpPath} 2>/dev/null || cat {$escapedTmpPath}) | mariadb -u root -p\$MARIADB_ROOT_PASSWORD \${MARIADB_DATABASE:-default}"
                : $baseCommand." < {$escapedTmpPath}",
            'standalone-mysql' => $dumpAll
                ? $baseCommand." && (gunzip -cf {$escapedTmpPath} 2>/dev/null || cat {$escapedTmpPath}) | mysql -u root -p\$MYSQL_ROOT_PASSWORD \${MYSQL_DATABASE:-default}"
                : $baseCommand." < {$escapedTmpPath}",
            'standalone-postgresql' => $dumpAll
                ? $baseCommand." && (gunzip -cf {$escapedTmpPath} 2>/dev/null || cat {$escapedTmpPath}) | psql -U \${POSTGRES_USER} -d \${POSTGRES_DB:-\${POSTGRES_USER:-postgres}}"
                : $baseCommand." {$escapedTmpPath}",
            'standalone-mongodb' => $baseCommand.$escapedTmpPath,
            default => '',
        };
    }

    private function importS3Disk(S3Storage $s3Storage): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::build([
            'driver' => 's3',
            'region' => $s3Storage->region,
            'key' => $s3Storage->key,
            'secret' => $s3Storage->secret,
            'bucket' => $s3Storage->bucket,
            'endpoint' => $s3Storage->endpoint,
            'use_path_style_endpoint' => true,
        ]);
    }

    private function validateBucketName(string $bucket): bool
    {
        return preg_match('/^[a-zA-Z0-9.\-_]+$/', $bucket) === 1;
    }

    private function validateS3Path(string $path): bool
    {
        if (empty($path)) {
            return false;
        }
        foreach (['..', '$(', '`', '|', ';', '&', '>', '<', "\n", "\r", "\0", "'", '"', '\\'] as $pattern) {
            if (str_contains($path, $pattern)) {
                return false;
            }
        }

        return preg_match('/^[a-zA-Z0-9.\-_\/\s+@=]+$/', $path) === 1;
    }

    private function validateServerPath(string $path): bool
    {
        if (! str_starts_with($path, '/')) {
            return false;
        }
        foreach (['..', '$(', '`', '|', ';', '&', '>', '<', "\n", "\r", "\0", "'", '"', '\\'] as $pattern) {
            if (str_contains($path, $pattern)) {
                return false;
            }
        }

        return preg_match('/^[a-zA-Z0-9.\-_\/\s]+$/', $path) === 1;
    }
}
