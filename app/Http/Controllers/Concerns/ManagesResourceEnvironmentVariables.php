<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Service;
use App\Support\ValidationPatterns;
use App\Traits\EnvironmentVariableAnalyzer;
use App\Traits\EnvironmentVariableProtection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * React port of the former App\Livewire\Project\Shared\EnvironmentVariable\{All,Add,Show,
 * ShowHardcoded} family — standalone databases and services (Phase 56), joined by Application on
 * its production (non-preview) variable set (Phase 65). The original's Application-only extra
 * modes (preview-deployment variables, build secrets, the sort-alphabetically toggle) were
 * deliberately left out at the time, since Preview Deployments hadn't been converted yet — it
 * has been since (PreviewDeploymentsTab.jsx), but these three modes were never subsequently
 * ported here.
 *
 * Faithful-port notes:
 * - Locked (shown-once) and magic (SERVICE_FQDN, SERVICE_URL, SERVICE_NAME prefixed) variables
 *   only accept comment updates, mirroring the original blade's disabled inputs.
 * - Redis credentials (REDIS_PASSWORD/REDIS_USERNAME on standalone-redis) keep their key and
 *   require a non-empty value.
 * - Deleting/bulk-removing a variable still referenced by a service's or a docker-compose
 *   Application's compose file is blocked (EnvironmentVariableProtection), same as the
 *   original — `usesDockerCompose()`/`dockerComposeContent()` cover both resource shapes
 *   (Service's `docker_compose`, Application's `docker_compose_raw`).
 * - Hardcoded (compose-file-defined) variables surface for services and dockercompose-build-pack
 *   Applications alike, matching the original's `getHardcodedVariables()` condition exactly.
 * - The bulk "developer view" save ports handleBulkSubmit + updateOrder for production
 *   variables (db/service resources have no preview set; Application's preview set is one of
 *   the deferred extra modes above).
 */
trait ManagesResourceEnvironmentVariables
{
    use EnvironmentVariableAnalyzer;
    use EnvironmentVariableProtection;

    private const MAGIC_ENV_PREFIXES = ['SERVICE_FQDN', 'SERVICE_URL', 'SERVICE_NAME'];

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function environmentVariablesTabProps(Model $resource, array $parameters, string $routePrefix): array
    {
        $envs = $resource->environment_variables()
            ->orderByRaw("CASE WHEN is_required = true AND (value IS NULL OR value = '') THEN 0 ELSE 1 END")
            ->orderBy('order')
            ->get();

        return [
            'envs' => $envs->map(fn (EnvironmentVariable $env) => $this->envProps($env, $resource, $parameters, $routePrefix))->values(),
            'hardcodedEnvs' => $this->hardcodedEnvProps($resource),
            'devEnvs' => $this->formatDevEnvs($envs),
            'canManageEnvironment' => auth()->user()->can('manageEnvironment', $resource),
            'problematicVariables' => self::getProblematicVariablesForFrontend(),
            'availableSharedVariables' => $this->availableSharedVariableKeys($parameters),
            'envUrls' => [
                'store' => route("{$routePrefix}.envs.store", $parameters),
                'bulkUpdate' => route("{$routePrefix}.envs.bulk-update", $parameters),
            ],
        ];
    }

    private function envStore(Request $request, Model $resource): RedirectResponse
    {
        $this->authorize('manageEnvironment', $resource);

        $validated = Validator::make($request->all(), [
            'key' => ValidationPatterns::environmentVariableKeyRules(),
            'value' => 'nullable|string',
            'comment' => 'nullable|string|max:256',
            'is_multiline' => 'required|boolean',
            'is_literal' => 'required|boolean',
            'is_runtime' => 'required|boolean',
            'is_buildtime' => 'required|boolean',
        ], ValidationPatterns::environmentVariableKeyMessages('key'))->validate();

        $key = ValidationPatterns::validatedEnvironmentVariableKey($validated['key']);
        if ($resource->environment_variables()->where('key', $key)->exists()) {
            return back()->with('error', 'Environment variable already exists.');
        }

        $maxOrder = $resource->environment_variables()->max('order') ?? 0;
        $environment = new EnvironmentVariable;
        $environment->key = $key;
        $environment->value = $validated['value'] ?? '';
        $environment->comment = $validated['comment'] ?? null;
        $environment->is_multiline = (bool) $validated['is_multiline'];
        $environment->is_literal = (bool) $validated['is_literal'];
        $environment->is_runtime = (bool) $validated['is_runtime'];
        $environment->is_buildtime = (bool) $validated['is_buildtime'];
        $environment->is_preview = false;
        $environment->resourceable_id = $resource->id;
        $environment->resourceable_type = $resource->getMorphClass();
        $environment->order = $maxOrder + 1;
        $environment->save();

        return back()->with('success', 'Environment variable added.');
    }

    private function envUpdate(Request $request, Model $resource, string $env_id): RedirectResponse
    {
        $env = $resource->environment_variables()->findOrFail($env_id);
        $this->authorize('update', $env);

        $isMagic = str($env->key)->startsWith(self::MAGIC_ENV_PREFIXES);
        $isLocked = (bool) $env->is_shown_once;

        $validated = Validator::make($request->all(), [
            'key' => ValidationPatterns::environmentVariableKeyRules(),
            'value' => 'nullable|string',
            'comment' => 'nullable|string|max:256',
            'is_multiline' => 'required|boolean',
            'is_literal' => 'required|boolean',
            'is_runtime' => 'required|boolean',
            'is_buildtime' => 'required|boolean',
        ], ValidationPatterns::environmentVariableKeyMessages('key'))->validate();

        if ($isMagic || $isLocked) {
            $env->comment = $validated['comment'] ?? null;
            $env->save();

            return back()->with('success', 'Environment variable updated.');
        }

        if ($env->is_required && str($validated['value'] ?? '')->isEmpty()) {
            return back()->with('error', 'Required environment variables cannot be empty.');
        }

        $isRedisCredential = $resource->type() === 'standalone-redis'
            && in_array($env->key, ['REDIS_PASSWORD', 'REDIS_USERNAME'], true);
        if (! $isRedisCredential) {
            $env->key = ValidationPatterns::normalizeEnvironmentVariableKey($validated['key']);
        } elseif (str($validated['value'] ?? '')->isEmpty()) {
            return back()->with('error', 'Redis credentials cannot be empty.');
        }

        $env->value = $validated['value'] ?? '';
        $env->comment = $validated['comment'] ?? null;
        $env->is_multiline = (bool) $validated['is_multiline'];
        $env->is_literal = (bool) $validated['is_literal'];
        $env->is_runtime = (bool) $validated['is_runtime'];
        $env->is_buildtime = (bool) $validated['is_buildtime'];
        $env->save();

        return back()->with('success', 'Environment variable updated.');
    }

    private function envLock(Model $resource, string $env_id): RedirectResponse
    {
        $env = $resource->environment_variables()->findOrFail($env_id);
        $this->authorize('update', $env);

        $env->is_shown_once = true;
        $env->save();

        return back()->with('success', 'Environment variable locked.');
    }

    private function envDestroy(Model $resource, string $env_id): RedirectResponse
    {
        $env = $resource->environment_variables()->findOrFail($env_id);
        $this->authorize('delete', $env);

        if ($this->usesDockerCompose($resource)) {
            [$isUsed] = $this->isEnvironmentVariableUsedInDockerCompose($env->key, $this->dockerComposeContent($resource));
            if ($isUsed) {
                return back()->with('error', "Cannot delete environment variable '{$env->key}'. Please remove it from the Docker Compose file first.");
            }
        }

        $env->delete();

        return back()->with('success', 'Environment variable deleted successfully.');
    }

    private function envBulkUpdate(Request $request, Model $resource): RedirectResponse
    {
        $this->authorize('manageEnvironment', $resource);

        $validated = Validator::make($request->all(), [
            'variables' => 'nullable|string',
        ])->validate();

        try {
            $variables = collect(parseEnvFormatToArray($validated['variables'] ?? ''))
                ->mapWithKeys(fn ($data, $key) => [ValidationPatterns::validatedEnvironmentVariableKey((string) $key) => $data])
                ->all();
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        // Delete removed variables unless docker-compose still references them
        $variablesToDelete = $resource->environment_variables()->whereNotIn('key', array_keys($variables))->get();
        foreach ($variablesToDelete as $envVar) {
            if ($this->usesDockerCompose($resource)) {
                [$isUsed] = $this->isEnvironmentVariableUsedInDockerCompose($envVar->key, $this->dockerComposeContent($resource));
                if ($isUsed) {
                    return back()->with('error', "Cannot delete environment variable '{$envVar->key}'. Please remove it from the Docker Compose file first.");
                }
            }
        }
        $resource->environment_variables()->whereNotIn('key', array_keys($variables))->delete();

        foreach ($variables as $key => $data) {
            if (str($key)->startsWith(self::MAGIC_ENV_PREFIXES)) {
                continue;
            }
            $value = is_array($data) ? ($data['value'] ?? '') : $data;
            $comment = is_array($data) ? ($data['comment'] ?? null) : null;

            $found = $resource->environment_variables()->where('key', $key)->first();
            if ($found) {
                if (! $found->is_shown_once && ! $found->is_multiline) {
                    if ($found->value !== $value) {
                        $found->value = $value;
                    }
                    if ($comment !== null) {
                        $found->comment = $comment;
                    }
                    $found->save();
                }
            } else {
                $environment = new EnvironmentVariable;
                $environment->key = $key;
                $environment->value = $value;
                $environment->comment = $comment;
                $environment->is_multiline = false;
                $environment->is_preview = false;
                $environment->resourceable_id = $resource->id;
                $environment->resourceable_type = $resource->getMorphClass();
                $environment->save();
            }
        }

        // Persist the textarea ordering (original updateOrder)
        $order = 1;
        foreach (array_keys($variables) as $key) {
            $env = $resource->environment_variables()->where('key', $key)->first();
            if ($env) {
                $env->order = $order;
                $env->save();
            }
            $order++;
        }

        return back()->with('success', 'Environment variables updated.');
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function envProps(EnvironmentVariable $env, Model $resource, array $parameters, string $routePrefix): array
    {
        $isMagic = str($env->key)->startsWith(self::MAGIC_ENV_PREFIXES);
        $isLocked = (bool) $env->is_shown_once;
        $envParams = [...$parameters, 'env_id' => $env->id];

        return [
            'id' => $env->id,
            'key' => $env->key,
            // Locked (shown-once) values are never sent back to the client, same as the blade
            'value' => $isLocked ? null : $env->value,
            'realValue' => (! $isLocked && $env->is_shared) ? $env->real_value : null,
            'comment' => $env->comment,
            'isMultiline' => (bool) $env->is_multiline,
            'isLiteral' => (bool) $env->is_literal,
            'isRuntime' => (bool) ($env->is_runtime ?? true),
            'isBuildtime' => (bool) ($env->is_buildtime ?? true),
            'isBuildpackControl' => (bool) ($env->is_buildpack_control ?? false),
            'isShared' => (bool) ($env->is_shared ?? false),
            'isRequired' => (bool) ($env->is_required ?? false),
            'isReallyRequired' => (bool) ($env->is_really_required ?? false),
            'isLocked' => $isLocked,
            'isMagic' => $isMagic,
            'isRedisCredential' => $resource->type() === 'standalone-redis'
                && in_array($env->key, ['REDIS_PASSWORD', 'REDIS_USERNAME'], true),
            'urls' => [
                'update' => route("{$routePrefix}.envs.update", $envParams),
                'lock' => route("{$routePrefix}.envs.lock", $envParams),
                'destroy' => route("{$routePrefix}.envs.destroy", $envParams),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function hardcodedEnvProps(Model $resource): array
    {
        if (! $this->usesDockerCompose($resource)) {
            return [];
        }
        $dockerComposeRaw = data_get($resource, 'docker_compose_raw') ?? data_get($resource, 'docker_compose');
        if (blank($dockerComposeRaw)) {
            return [];
        }

        $managedKeys = $resource->environment_variables()->where('is_preview', false)->pluck('key')->toArray();

        return extractHardcodedEnvironmentVariables($dockerComposeRaw)
            ->filter(fn ($var) => ! str($var['key'])->startsWith(['SERVICE_FQDN_', 'SERVICE_URL_', 'SERVICE_NAME_']))
            ->filter(fn ($var) => ! in_array($var['key'], $managedKeys, true))
            ->values()
            ->all();
    }

    /**
     * Port of the original's mixed conditions for "does this resource's env vars need
     * docker-compose cross-referencing" — true for services, and for Application only when
     * its build pack is dockercompose (a plain git/dockerfile Application never has compose
     * content to check against).
     */
    private function usesDockerCompose(Model $resource): bool
    {
        return $resource->type() === 'service' || data_get($resource, 'build_pack') === 'dockercompose';
    }

    /**
     * The compose-in-use check for envDestroy/envBulkUpdate deliberately reads a single,
     * type-specific field rather than hardcodedEnvProps' raw-preferred fallback: a Service's
     * `docker_compose_raw` column is NOT NULL (defaults to '', not null, so `??` never falls
     * through to `docker_compose` the way it needs to here), and Application has no
     * `docker_compose` column at all — only `docker_compose_raw`.
     */
    private function dockerComposeContent(Model $resource): ?string
    {
        return $resource->type() === 'service'
            ? data_get($resource, 'docker_compose')
            : data_get($resource, 'docker_compose_raw');
    }

    private function formatDevEnvs($envs): string
    {
        return $envs->map(function (EnvironmentVariable $item) {
            if ($item->is_shown_once) {
                return "$item->key=(Locked Secret, delete and add again to change)";
            }
            if ($item->is_multiline) {
                return "$item->key=(Multiline environment variable, edit in normal view)";
            }

            return "$item->key=$item->value";
        })->join("\n");
    }

    /**
     * Port of Add/Show::availableSharedVariables() for the db/service parameter shapes
     * (application_uuid/server_uuid lookups belong to those pages' own conversions).
     *
     * @param  array<string, string>  $parameters
     * @return array<string, array<int, string>>
     */
    private function availableSharedVariableKeys(array $parameters): array
    {
        $team = currentTeam();
        $result = ['team' => [], 'project' => [], 'environment' => [], 'server' => []];
        if (! $team) {
            return $result;
        }

        try {
            $this->authorize('view', $team);
            $result['team'] = $team->environment_variables()->pluck('key')->toArray();
        } catch (AuthorizationException) {
        }

        $project = Project::where('team_id', $team->id)->where('uuid', data_get($parameters, 'project_uuid'))->first();
        if ($project) {
            try {
                $this->authorize('view', $project);
                $result['project'] = $project->environment_variables()->pluck('key')->toArray();
                $environment = $project->environments()->where('uuid', data_get($parameters, 'environment_uuid'))->first();
                if ($environment) {
                    try {
                        $this->authorize('view', $environment);
                        $result['environment'] = $environment->environment_variables()->pluck('key')->toArray();
                    } catch (AuthorizationException) {
                    }
                }
            } catch (AuthorizationException) {
            }
        }

        $serviceUuid = data_get($parameters, 'service_uuid');
        if ($serviceUuid) {
            $service = Service::whereRelation('environment.project.team', 'id', $team->id)
                ->where('uuid', $serviceUuid)
                ->with('server')
                ->first();
            if ($service && $service->server) {
                try {
                    $this->authorize('view', $service->server);
                    $result['server'] = $service->server->environment_variables()->pluck('key')->toArray();
                } catch (AuthorizationException) {
                }
            }
        }

        return $result;
    }
}
