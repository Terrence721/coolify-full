<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\Service;
use App\Models\StandaloneDatabaseInstance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The Danger Zone tab (App\Livewire\Project\Shared\Danger) — extracted from
 * ProjectDatabaseConfigurationController and ProjectServiceConfigurationController's
 * byte-identical inline implementations on their third consumer,
 * ProjectApplicationConfigurationController (Phase 63). Password-confirmed delete via
 * PasswordConfirmModal; the four checkboxes map straight to DeleteResourceJob's booleans,
 * which itself already branches on the resource's concrete type.
 */
trait ManagesResourceDanger
{
    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function dangerTabProps(Application|Service|StandaloneDatabaseInstance $resource, array $parameters, string $routePrefix): array
    {
        return [
            'resourceName' => $resource->name ?? 'Resource',
            'canDelete' => auth()->user()->can('delete', $resource),
            'destroyUrl' => route("{$routePrefix}.destroy", $parameters),
        ];
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function destroyResource(Request $request, Application|Service|StandaloneDatabaseInstance $resource, array $parameters): RedirectResponse
    {
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

        $this->authorize('delete', $resource);

        $resource->delete();
        DeleteResourceJob::dispatch(
            $resource,
            $request->boolean('delete_volumes'),
            $request->boolean('delete_connected_networks'),
            $request->boolean('delete_configurations'),
            $request->boolean('docker_cleanup'),
        );

        return redirect()->route('project.resource.index', [
            'project_uuid' => $parameters['project_uuid'],
            'environment_uuid' => $parameters['environment_uuid'],
        ]);
    }
}
