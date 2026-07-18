<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Models\StandaloneDatabaseInstance;
use App\Helpers\SslHelper;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Support\ValidationPatterns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * React port of the 8 per-engine General tabs (App\Livewire\Project\Database\{Postgresql,
 * Mysql,Mariadb,Mongodb,Redis,Keydb,Dragonfly,Clickhouse}\General, sharing the
 * HasDatabaseGeneralForm trait) plus their StatusInfo children (HasDatabaseStatusInfo) and,
 * for Postgres, the InitScript family — the last tab on the Database Configuration router
 * (Phase 62). Finishing this retires the Livewire shell (`Database\Configuration`) itself,
 * since every other tab already routes through the controller — along with `Database\Heading`
 * (only ever nested by that shell's blade).
 *
 * Engine differences ported faithfully, not generalized further than the originals were:
 * - All 8 engines' credential fields are validated `required` (every ValidationPatterns call
 *   in the originals used the default `$required = true`, regardless of what the blade's HTML
 *   `required` attribute showed) — `enforcePattern` still toggles off the regex when the
 *   submitted value is unchanged from the stored one, exactly matching the originals' legacy-
 *   data escape hatch.
 * - Save authorization is `update` for 6 engines and `manageEnvironment` for KeyDB and Redis
 *   (their own `submit()` overrides), matching the originals exactly.
 * - Redis is the one true bespoke case: `redis_username`/`redis_password` columns are never
 *   written by submit() — the live values are persisted into `runtime_environment_variables`
 *   (REDIS_USERNAME/REDIS_PASSWORD) instead, and the username field is hidden below Redis 6.0
 *   or when the corresponding env var is a shared variable.
 * - Only Mongo's submit() explicitly nulls a blank config textarea; the other 5 engines with a
 *   config field leave an empty string as-is, so that's the only engine with `nullifyEmptyConfig`.
 */
trait ManagesDatabaseGeneralForm
{
    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function generalFormTabProps(StandaloneDatabaseInstance $database, array $parameters, string $routePrefix): array
    {
        $spec = $this->engineFormSpec($database);
        $server = data_get($database, 'destination.server');
        $started = filled($database->started_at ?? null);
        $isExited = str((string) $database->status)->contains('exited');

        $credentials = collect($spec['credentials'])->map(function (array $field) use ($database, $started) {
            $value = $database->{$field['column']};

            return [
                'prop' => $field['prop'],
                'label' => $field['label'],
                'type' => $field['type'],
                'value' => $value,
                'readonly' => $started && ($field['readonlyWhenStarted'] ?? false),
                'helper' => $started ? ($field['helperStarted'] ?? null) : ($field['helperStopped'] ?? null),
                'placeholder' => $field['placeholder'] ?? null,
            ];
        })->values();

        $configField = $spec['configField'] === null ? null : [
            'prop' => $spec['configField']['prop'],
            'label' => $spec['configField']['label'],
            'value' => $database->{$spec['configField']['column']},
            'helper' => $spec['configField']['helper'] ?? null,
        ];

        $redisExtras = ($spec['engine'] === 'redis' && $database instanceof StandaloneRedis)
            ? $this->redisFormExtras($database)
            : null;

        return [
            'generalForm' => [
                'engine' => $spec['engine'],
                'label' => $spec['label'],
                'dockerHubUrl' => $spec['dockerHubUrl'],
                'name' => $database->name,
                'description' => $database->description,
                'image' => $database->image,
                'customDockerRunOptions' => $database->custom_docker_run_options,
                'portsMappings' => $database->ports_mappings,
                'isPublic' => (bool) $database->is_public,
                'publicPort' => $database->public_port,
                'publicPortTimeout' => $database->public_port_timeout,
                'isLogDrainEnabled' => (bool) $database->is_log_drain_enabled,
                'started' => $started,
                'isRunning' => str((string) $database->status)->startsWith('running'),
                'canUpdate' => auth()->user()->can('update', $database),
                'credentials' => $credentials,
                'configField' => $configField,
                'redis' => $redisExtras,
                'statusInfo' => $this->statusInfoProps($database, $spec, $isExited),
                'initScripts' => $spec['engine'] === 'postgresql' ? ($database->init_scripts ?? []) : null,
            ],
            'generalUrls' => [
                'update' => route("{$routePrefix}.general.update", $parameters),
                'updateProxy' => route("{$routePrefix}.general.proxy", $parameters),
                'updateAdvanced' => route("{$routePrefix}.general.advanced", $parameters),
                'updateSsl' => route("{$routePrefix}.general.ssl", $parameters),
                'regenerateSsl' => route("{$routePrefix}.general.ssl.regenerate", $parameters),
                'initScriptStore' => $spec['engine'] === 'postgresql' ? route("{$routePrefix}.general.init-scripts.store", $parameters) : null,
                'initScriptDestroy' => $spec['engine'] === 'postgresql' ? route("{$routePrefix}.general.init-scripts.destroy", $parameters) : null,
            ],
            'resourceDetails' => [
                'resource' => ['name' => $database->name, 'uuid' => $database->uuid],
                'environment' => ['name' => $database->environment?->name, 'uuid' => $database->environment?->uuid],
                'project' => ['name' => $database->environment?->project?->name, 'uuid' => $database->environment?->project?->uuid],
                'server' => $server ? ['name' => $server->name, 'uuid' => $server->uuid] : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function redisFormExtras(StandaloneRedis $database): array
    {
        $version = $database->getRedisVersion();
        $isSharedVariable = fn (string $key) => $database->runtime_environment_variables()->where('key', $key)->where('is_shared', true)->exists();

        return [
            'version' => $version,
            'showUsername' => version_compare($version, '6.0', '>='),
            'usernameLocked' => $isSharedVariable('REDIS_USERNAME'),
            'passwordLocked' => $isSharedVariable('REDIS_PASSWORD'),
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function statusInfoProps(StandaloneDatabaseInstance $database, array $spec, bool $isExited): array
    {
        $sslMode = $spec['sslModeOptions'] !== null ? $database->ssl_mode : null;

        return [
            'label' => $spec['label'],
            'dbUrl' => $database->internal_db_url,
            'dbUrlPublic' => $database->external_db_url,
            'showPublicUrlPlaceholder' => $spec['showPublicUrlPlaceholder'],
            'supportsSsl' => $spec['supportsSsl'],
            'enableSsl' => $spec['supportsSsl'] ? (bool) $database->enable_ssl : false,
            'sslMode' => $sslMode,
            'sslModeOptions' => $spec['sslModeOptions'],
            'sslModeHelper' => $spec['sslModeHelper'],
            'certificateValidUntil' => $spec['supportsSsl'] ? $database->sslCertificates()->first()?->valid_until?->toIso8601String() : null,
            'isExited' => $isExited,
        ];
    }

    public function updateDatabaseGeneral(Request $request, StandaloneDatabaseInstance $database): RedirectResponse
    {
        $spec = $this->engineFormSpec($database);
        $this->authorize($spec['authAbility'], $database);

        $rules = [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'image' => 'required|string',
            'portsMappings' => ValidationPatterns::portMappingRules(),
            'customDockerRunOptions' => 'nullable|string',
        ];
        $messages = ValidationPatterns::combinedMessages() + ValidationPatterns::portMappingMessages();

        foreach ($spec['credentials'] as $field) {
            $changed = $request->input($field['prop']) !== (string) $database->{$field['column']};
            $rules[$field['prop']] = $field['type'] === 'password'
                ? ValidationPatterns::databasePasswordRules(enforcePattern: $changed)
                : ValidationPatterns::databaseIdentifierRules(enforcePattern: $changed);
            $messages += $field['type'] === 'password'
                ? ValidationPatterns::databasePasswordMessages($field['prop'], $field['label'])
                : ValidationPatterns::databaseIdentifierMessages($field['prop'], $field['label']);
        }
        if ($spec['configField'] !== null) {
            $rules[$spec['configField']['prop']] = 'nullable|string';
        }

        $validated = Validator::make($request->all(), $rules, $messages)->validate();

        if (filled($validated['portsMappings'] ?? null)) {
            $validated['portsMappings'] = str($validated['portsMappings'])->replace(' ', '')->trim()->value();
        }

        $database->name = $validated['name'];
        $database->description = $validated['description'] ?? null;
        $database->image = $validated['image'];
        $database->ports_mappings = $validated['portsMappings'] ?? null;
        $database->custom_docker_run_options = $validated['customDockerRunOptions'] ?? null;

        foreach ($spec['credentials'] as $field) {
            // Redis is the one engine whose credential fields never land on the model itself
            // during submit() — they only ever get persisted into runtime_environment_variables
            // below, exactly like the original component.
            if ($spec['engine'] !== 'redis') {
                $database->{$field['column']} = $validated[$field['prop']];
            }
        }
        if ($spec['configField'] !== null) {
            $value = $validated[$spec['configField']['prop']] ?? null;
            if (($spec['configField']['nullifyEmptyConfig'] ?? false) && blank($value)) {
                $value = null;
            }
            $database->{$spec['configField']['column']} = $value;
        }

        $database->save();

        if ($spec['engine'] === 'redis' && $database instanceof StandaloneRedis) {
            $extras = $this->redisFormExtras($database);
            if ($extras['showUsername'] && ! $extras['usernameLocked']) {
                $database->runtime_environment_variables()->updateOrCreate(
                    ['key' => 'REDIS_USERNAME'],
                    ['value' => $validated['redisUsername'] ?? $database->redis_username, 'resourceable_id' => $database->id],
                );
            }
            if (! $extras['passwordLocked']) {
                $database->runtime_environment_variables()->updateOrCreate(
                    ['key' => 'REDIS_PASSWORD'],
                    ['value' => $validated['redisPassword'] ?? $database->redis_password, 'resourceable_id' => $database->id],
                );
            }
        }

        if (is_null($database->config_hash)) {
            $database->isConfigurationChanged(true);
        }

        return back()->with('success', 'Database updated.');
    }

    public function updateDatabaseProxy(Request $request, StandaloneDatabaseInstance $database): RedirectResponse
    {
        $this->authorize('update', $database);

        $validated = Validator::make($request->all(), [
            'isPublic' => 'required|boolean',
            'publicPort' => 'nullable|integer|min:1|max:65535',
            'publicPortTimeout' => 'nullable|integer|min:1',
        ])->validate();

        $isPublic = $validated['isPublic'];

        if ($isPublic && ! filled($validated['publicPort'] ?? null)) {
            return back()->with('error', 'Public port is required.');
        }
        if ($isPublic && ! str((string) $database->status)->startsWith('running')) {
            return back()->with('error', 'Database must be started to be publicly accessible.');
        }

        $database->is_public = $isPublic;
        // Only overwrite the port fields when the request actually carries them — the
        // React form always sends both, but a disable-only request shouldn't wipe a
        // previously configured port, matching the original's Livewire round-trip
        // behavior (its bound properties simply kept their prior in-memory value).
        if ($request->has('publicPort')) {
            $database->public_port = $validated['publicPort'] ?? null;
        }
        if ($request->has('publicPortTimeout')) {
            $database->public_port_timeout = $validated['publicPortTimeout'] ?? null;
        }
        $database->save();

        if ($isPublic) {
            StartDatabaseProxy::run($database);

            return back()->with('success', 'Database is now publicly accessible.');
        }

        StopDatabaseProxy::run($database);

        return back()->with('success', 'Database is no longer publicly accessible.');
    }

    public function updateDatabaseAdvanced(Request $request, StandaloneDatabaseInstance $database): RedirectResponse
    {
        $this->authorize('update', $database);

        $validated = Validator::make($request->all(), [
            'isLogDrainEnabled' => 'required|boolean',
        ])->validate();

        $server = data_get($database, 'destination.server');
        if ($validated['isLogDrainEnabled'] && (! $server || ! $server->isLogDrainEnabled())) {
            return back()->with('error', 'Log drain is not enabled on the server. Please enable it first.');
        }

        $database->is_log_drain_enabled = $validated['isLogDrainEnabled'];
        $database->save();

        return back()->with('success', 'Database updated. You need to restart the service for the changes to take effect.');
    }

    public function updateDatabaseSsl(Request $request, StandaloneDatabaseInstance $database): RedirectResponse
    {
        $this->authorize('update', $database);
        $spec = $this->engineFormSpec($database);

        $rules = ['enableSsl' => 'required|boolean'];
        if ($spec['sslModeOptions'] !== null) {
            $rules['sslMode'] = 'nullable|string|in:'.implode(',', array_keys($spec['sslModeOptions']));
        }
        $validated = Validator::make($request->all(), $rules)->validate();

        $database->enable_ssl = $validated['enableSsl'];
        if ($spec['sslModeOptions'] !== null && array_key_exists('sslMode', $validated)) {
            $database->ssl_mode = $validated['sslMode'];
        }
        $database->save();

        return back()->with('success', 'SSL configuration updated.');
    }

    public function regenerateDatabaseSslCertificate(StandaloneDatabaseInstance $database): RedirectResponse
    {
        $this->authorize('update', $database);

        $existingCert = $database->sslCertificates()->first();
        if (! $existingCert) {
            return back()->with('error', 'No existing SSL certificate found for this database.');
        }

        $server = $database->destination->server;
        $caCert = $server->sslCertificates()->where('is_ca_certificate', true)->first();
        if (! $caCert) {
            $server->generateCaCertificate();
            $caCert = $server->sslCertificates()->where('is_ca_certificate', true)->first();
        }
        if (! $caCert) {
            return back()->with('error', 'No CA certificate found for this database. Please generate a CA certificate for this server in the server/advanced page.');
        }

        SslHelper::generateSslCertificate(
            commonName: $existingCert->common_name,
            subjectAlternativeNames: $existingCert->subject_alternative_names ?? [],
            resourceType: $existingCert->resource_type,
            resourceId: $existingCert->resource_id,
            serverId: $existingCert->server_id,
            caCert: $caCert->ssl_certificate,
            caKey: $caCert->ssl_private_key,
            configurationDir: $existingCert->configuration_dir,
            mountPath: $existingCert->mount_path,
            isPemKeyFileRequired: true,
        );

        return back()->with('success', 'SSL certificates regenerated. Restart database to apply changes.');
    }

    public function storeDatabaseInitScript(Request $request, StandalonePostgresql $database): RedirectResponse
    {
        $this->authorize('update', $database);

        $validated = Validator::make($request->all(), [
            'filename' => 'required|string',
            'content' => 'required|string',
            'index' => 'nullable|integer',
        ])->validate();

        try {
            validateFilenameSafe($validated['filename'], 'init script filename');
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in storeDatabaseInitScript().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }

        $initScripts = collect($database->init_scripts ?? []);
        $existingIndex = $validated['index'] ?? null;

        $duplicateFilename = $initScripts->firstWhere('filename', $validated['filename']);
        if ($duplicateFilename && (int) $duplicateFilename['index'] !== $existingIndex) {
            return back()->with('error', 'A script with this filename already exists.');
        }

        $oldScript = $existingIndex !== null ? $initScripts->firstWhere('index', $existingIndex) : null;
        if ($oldScript && $oldScript['filename'] !== $validated['filename']) {
            $configurationDir = database_configuration_dir().'/'.$database->uuid;
            $oldFilename = basename((string) $oldScript['filename']);
            $oldFilePath = "{$configurationDir}/docker-entrypoint-initdb.d/{$oldFilename}";
            $escapedOldPath = escapeshellarg($oldFilePath);
            instant_remote_process(["rm -f {$escapedOldPath}"], $database->destination->server);
        }

        $matchIndex = $existingIndex !== null ? $initScripts->search(fn ($item) => $item['index'] === $existingIndex) : false;
        $script = ['filename' => $validated['filename'], 'content' => $validated['content'], 'index' => $existingIndex ?? $initScripts->count()];

        if ($matchIndex !== false) {
            $initScripts[$matchIndex] = $script;
        } else {
            $initScripts->push($script);
        }

        $database->init_scripts = $initScripts->values()
            ->map(fn ($item, $i) => [...$item, 'index' => $i])
            ->all();
        $database->save();

        return back()->with('success', $existingIndex !== null ? 'Init script saved and updated.' : 'Init script added.');
    }

    public function destroyDatabaseInitScript(Request $request, StandalonePostgresql $database): RedirectResponse
    {
        $this->authorize('update', $database);

        $validated = Validator::make($request->all(), [
            'filename' => 'required|string',
        ])->validate();

        $collection = collect($database->init_scripts ?? []);
        $found = $collection->firstWhere('filename', $validated['filename']);
        if (! $found) {
            return back();
        }

        $configurationDir = database_configuration_dir().'/'.$database->uuid;
        $safeFilename = basename((string) $found['filename']);
        $filePath = "{$configurationDir}/docker-entrypoint-initdb.d/{$safeFilename}";
        $escapedPath = escapeshellarg($filePath);
        instant_remote_process(["rm -f {$escapedPath}"], $database->destination->server);

        $database->init_scripts = $collection->filter(fn ($s) => $s['filename'] !== $validated['filename'])
            ->values()
            ->map(fn ($item, $i) => [...$item, 'index' => $i])
            ->all();
        $database->save();

        return back()->with('success', 'Init script deleted from the database and the server.');
    }

    /**
     * The per-engine field table — column names, labels, credential-field shapes, and
     * StatusInfo's SSL support, mirroring each engine's General/StatusInfo class pair.
     *
     * @return array<string, mixed>
     */
    private function engineFormSpec(StandaloneDatabaseInstance $database): array
    {
        return match (true) {
            $database instanceof StandalonePostgresql => [
                'engine' => 'postgresql',
                'label' => 'Postgres',
                'dockerHubUrl' => 'https://hub.docker.com/_/postgres',
                'authAbility' => 'update',
                'credentials' => [
                    ['prop' => 'postgresUser', 'column' => 'postgres_user', 'label' => 'Username', 'type' => 'text', 'placeholder' => 'If empty: postgres', 'helperStarted' => 'If you change this in the database, please sync it here, otherwise automations (like backups) won\'t work.'],
                    ['prop' => 'postgresPassword', 'column' => 'postgres_password', 'label' => 'Password', 'type' => 'password', 'helperStarted' => 'If you change this in the database, please sync it here, otherwise automations (like backups) won\'t work.'],
                    ['prop' => 'postgresDb', 'column' => 'postgres_db', 'label' => 'Initial Database', 'type' => 'text', 'placeholder' => 'If empty, it will be the same as Username.', 'readonlyWhenStarted' => true, 'helperStarted' => 'You can only change this in the database.'],
                ],
                'configField' => ['prop' => 'postgresConf', 'column' => 'postgres_conf', 'label' => 'Custom PostgreSQL Configuration'],
                'sslModeOptions' => [
                    'allow' => ['title' => 'Allow insecure connections', 'label' => 'allow (insecure)'],
                    'prefer' => ['title' => 'Prefer secure connections', 'label' => 'prefer (secure)'],
                    'require' => ['title' => 'Require secure connections', 'label' => 'require (secure)'],
                    'verify-ca' => ['title' => 'Verify CA certificate', 'label' => 'verify-ca (secure)'],
                    'verify-full' => ['title' => 'Verify full certificate', 'label' => 'verify-full (secure)'],
                ],
                'sslModeHelper' => 'Choose the SSL verification mode for PostgreSQL connections',
                'supportsSsl' => true,
                'showPublicUrlPlaceholder' => false,
            ],
            $database instanceof StandaloneMysql => [
                'engine' => 'mysql',
                'label' => 'MySQL',
                'dockerHubUrl' => 'https://hub.docker.com/_/mysql',
                'authAbility' => 'update',
                'credentials' => [
                    ['prop' => 'mysqlRootPassword', 'column' => 'mysql_root_password', 'label' => 'Root Password', 'type' => 'password', 'helperStarted' => 'If you change this in the database, please sync it here, otherwise automations (like backups) won\'t work.'],
                    ['prop' => 'mysqlUser', 'column' => 'mysql_user', 'label' => 'Normal User', 'type' => 'text', 'helperStarted' => 'If you change this in the database, please sync it here, otherwise automations (like backups) won\'t work.'],
                    ['prop' => 'mysqlPassword', 'column' => 'mysql_password', 'label' => 'Normal User Password', 'type' => 'password', 'helperStarted' => 'If you change this in the database, please sync it here, otherwise automations (like backups) won\'t work.'],
                    ['prop' => 'mysqlDatabase', 'column' => 'mysql_database', 'label' => 'Initial Database', 'type' => 'text', 'placeholder' => 'If empty, it will be the same as Username.', 'readonlyWhenStarted' => true, 'helperStarted' => 'You can only change this in the database.', 'helperStopped' => 'You can only change this in the database.'],
                ],
                'configField' => ['prop' => 'mysqlConf', 'column' => 'mysql_conf', 'label' => 'Custom Mysql Configuration'],
                'sslModeOptions' => [
                    'PREFERRED' => ['title' => 'Prefer secure connections', 'label' => 'Prefer (secure)'],
                    'REQUIRED' => ['title' => 'Require secure connections', 'label' => 'Require (secure)'],
                    'VERIFY_CA' => ['title' => 'Verify CA certificate', 'label' => 'Verify CA (secure)'],
                    'VERIFY_IDENTITY' => ['title' => 'Verify full certificate', 'label' => 'Verify Full (secure)'],
                ],
                'sslModeHelper' => 'Choose the SSL verification mode for MySQL connections',
                'supportsSsl' => true,
                'showPublicUrlPlaceholder' => false,
            ],
            $database instanceof StandaloneMariadb => [
                'engine' => 'mariadb',
                'label' => 'MariaDB',
                'dockerHubUrl' => 'https://hub.docker.com/_/mariadb',
                'authAbility' => 'update',
                'credentials' => [
                    ['prop' => 'mariadbRootPassword', 'column' => 'mariadb_root_password', 'label' => 'Root Password', 'type' => 'password', 'helperStarted' => 'If you change this in the database, please sync it here, otherwise automations (like backups) won\'t work.'],
                    ['prop' => 'mariadbUser', 'column' => 'mariadb_user', 'label' => 'Normal User', 'type' => 'text', 'helperStarted' => 'If you change this in the database, please sync it here, otherwise automations (like backups) won\'t work.'],
                    ['prop' => 'mariadbPassword', 'column' => 'mariadb_password', 'label' => 'Normal User Password', 'type' => 'password', 'helperStarted' => 'If you change this in the database, please sync it here, otherwise automations (like backups) won\'t work.'],
                    ['prop' => 'mariadbDatabase', 'column' => 'mariadb_database', 'label' => 'Initial Database', 'type' => 'text', 'placeholder' => 'If empty, it will be the same as Username.', 'readonlyWhenStarted' => true, 'helperStarted' => 'You can only change this in the database.', 'helperStopped' => 'You can only change this in the database.'],
                ],
                'configField' => ['prop' => 'mariadbConf', 'column' => 'mariadb_conf', 'label' => 'Custom MariaDB Configuration'],
                'sslModeOptions' => null,
                'sslModeHelper' => null,
                'supportsSsl' => true,
                'showPublicUrlPlaceholder' => false,
            ],
            $database instanceof StandaloneMongodb => [
                'engine' => 'mongodb',
                'label' => 'Mongo',
                'dockerHubUrl' => 'https://hub.docker.com/_/mongo',
                'authAbility' => 'update',
                'credentials' => [
                    ['prop' => 'mongoInitdbRootUsername', 'column' => 'mongo_initdb_root_username', 'label' => 'Initial Username', 'type' => 'text', 'placeholder' => 'If empty: postgres', 'helperStarted' => 'If you change this in the database, please sync it here, otherwise automations (like backups) won\'t work.'],
                    ['prop' => 'mongoInitdbRootPassword', 'column' => 'mongo_initdb_root_password', 'label' => 'Initial Password', 'type' => 'password', 'helperStarted' => 'If you change this in the database, please sync it here, otherwise automations (like backups) won\'t work.'],
                    ['prop' => 'mongoInitdbDatabase', 'column' => 'mongo_initdb_database', 'label' => 'Initial Database', 'type' => 'text', 'placeholder' => 'If empty, it will be the same as Username.', 'readonlyWhenStarted' => true, 'helperStarted' => 'You can only change this in the database.'],
                ],
                'configField' => ['prop' => 'mongoConf', 'column' => 'mongo_conf', 'label' => 'Custom MongoDB Configuration', 'nullifyEmptyConfig' => true],
                'sslModeOptions' => [
                    'allow' => ['title' => 'Allow insecure connections', 'label' => 'allow (insecure)'],
                    'prefer' => ['title' => 'Prefer secure connections', 'label' => 'prefer (secure)'],
                    'require' => ['title' => 'Require secure connections', 'label' => 'require (secure)'],
                    'verify-full' => ['title' => 'Verify full certificate', 'label' => 'verify-full (secure)'],
                ],
                'sslModeHelper' => 'Choose the SSL verification mode for MongoDB connections',
                'supportsSsl' => true,
                'showPublicUrlPlaceholder' => false,
            ],
            $database instanceof StandaloneKeydb => [
                'engine' => 'keydb',
                'label' => 'KeyDB',
                'dockerHubUrl' => 'https://hub.docker.com/r/eqalpha/keydb',
                'authAbility' => 'manageEnvironment',
                'credentials' => [
                    ['prop' => 'keydbPassword', 'column' => 'keydb_password', 'label' => 'Password', 'type' => 'password', 'readonlyWhenStarted' => true, 'helperStarted' => 'You can only change this in the database.'],
                ],
                'configField' => ['prop' => 'keydbConf', 'column' => 'keydb_conf', 'label' => 'Custom KeyDB Configuration', 'helper' => 'See the KeyDB default configuration: https://raw.githubusercontent.com/Snapchat/KeyDB/unstable/keydb.conf'],
                'sslModeOptions' => null,
                'sslModeHelper' => null,
                'supportsSsl' => true,
                'showPublicUrlPlaceholder' => true,
            ],
            $database instanceof StandaloneRedis => [
                'engine' => 'redis',
                'label' => 'Redis',
                'dockerHubUrl' => 'https://hub.docker.com/_/redis',
                'authAbility' => 'manageEnvironment',
                'credentials' => [
                    ['prop' => 'redisUsername', 'column' => 'redis_username', 'label' => 'Username', 'type' => 'text', 'helperStarted' => 'You can only change this in the database.'],
                    ['prop' => 'redisPassword', 'column' => 'redis_password', 'label' => 'Password', 'type' => 'password', 'helperStarted' => 'You can only change this in the database.'],
                ],
                'configField' => ['prop' => 'redisConf', 'column' => 'redis_conf', 'label' => 'Custom Redis Configuration', 'helper' => 'You only need to provide the Redis directives you want to override. Coolify automatically applies requirepass using the password above.'],
                'sslModeOptions' => null,
                'sslModeHelper' => null,
                'supportsSsl' => true,
                'showPublicUrlPlaceholder' => false,
            ],
            $database instanceof StandaloneDragonfly => [
                'engine' => 'dragonfly',
                'label' => 'Dragonfly',
                'dockerHubUrl' => null,
                'authAbility' => 'update',
                'credentials' => [
                    ['prop' => 'dragonflyPassword', 'column' => 'dragonfly_password', 'label' => 'Password', 'type' => 'password', 'readonlyWhenStarted' => true, 'helperStarted' => 'You can only change this in the database.'],
                ],
                'configField' => null,
                'sslModeOptions' => null,
                'sslModeHelper' => null,
                'supportsSsl' => true,
                'showPublicUrlPlaceholder' => true,
            ],
            $database instanceof StandaloneClickhouse => [
                'engine' => 'clickhouse',
                'label' => 'Clickhouse',
                'dockerHubUrl' => null,
                'authAbility' => 'update',
                'credentials' => [
                    ['prop' => 'clickhouseAdminUser', 'column' => 'clickhouse_admin_user', 'label' => 'Admin User', 'type' => 'text', 'helperStarted' => 'You can only change this in the database.'],
                    ['prop' => 'clickhouseAdminPassword', 'column' => 'clickhouse_admin_password', 'label' => 'Admin Password', 'type' => 'password', 'helperStarted' => 'You can only change this in the database.'],
                ],
                'configField' => null,
                'sslModeOptions' => null,
                'sslModeHelper' => null,
                'supportsSsl' => false,
                'showPublicUrlPlaceholder' => true,
            ],
            default => throw new \InvalidArgumentException('Unsupported database engine: '.get_class($database)),
        };
    }
}
