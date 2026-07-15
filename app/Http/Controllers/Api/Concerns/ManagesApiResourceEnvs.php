<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * JSON/API port of the read-only envs() GET shared by ApplicationsController,
 * DatabasesController, and ServicesController. Mechanical across all three
 * except one real, load-bearing branch: Application merges in its PR-preview
 * environment variables (environment_variables_preview), a concept that
 * doesn't exist for Database or Service. The four write endpoints
 * (create_env, update_env_by_uuid, create_bulk_envs, delete_env_by_uuid) are
 * NOT part of this trait — update_env_by_uuid() in particular has its own
 * ~55-line is_preview branch that would need the same per-type branching
 * this trait deliberately avoids, so those stay per-controller.
 */
trait ManagesApiResourceEnvs
{
    /**
     * @return Collection<int, mixed>
     */
    private function apiResourceEnvs(Model $resource): Collection
    {
        if ($resource instanceof Application) {
            return $resource->environment_variables->sortBy('id')
                ->merge($resource->environment_variables_preview->sortBy('id'));
        }

        // Database accesses this as a plain property, Service via an explicit ->get() call
        // in the original controllers — both resolve to the same relation data through
        // Eloquent's magic property access, so one form covers both here.
        return $resource->environment_variables;
    }

    /**
     * @param  array<int, string>  $hiddenForeignKeys
     */
    private function apiEnvsPayload(Model $resource, array $hiddenForeignKeys, callable $redactor): JsonResponse
    {
        $envs = $this->apiResourceEnvs($resource)->map(function ($env) use ($hiddenForeignKeys, $redactor) {
            if (! empty($hiddenForeignKeys)) {
                $env->makeHidden($hiddenForeignKeys);
            }

            return $redactor($env);
        });

        return response()->json($envs);
    }
}
