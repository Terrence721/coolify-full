<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Support\DatabaseEngineRegistry;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class DestinationController extends Controller
{
    use AuthorizesRequests;

    public function show(string $destination_uuid): Response|RedirectResponse
    {
        $destination = find_destination_for_current_team($destination_uuid);
        if (! $destination) {
            return redirect()->route('destination.index');
        }

        return Inertia::render('Destination/Show', [
            'destination' => [
                'uuid' => $destination->uuid,
                'name' => $destination->name,
                'network' => $destination->network,
                'serverIp' => $destination->server->ip,
                'isStandaloneDocker' => $destination->getMorphClass() === StandaloneDocker::class,
            ],
            'canUpdate' => request()->user()->can('update', $destination),
            'canDelete' => request()->user()->can('delete', $destination),
            'resourcesUrl' => route('destination.resources', ['destination_uuid' => $destination->uuid]),
            'updateUrl' => route('destination.update', ['destination_uuid' => $destination->uuid]),
            'deleteUrl' => route('destination.destroy', ['destination_uuid' => $destination->uuid]),
        ]);
    }

    public function resources(string $destination_uuid): Response|RedirectResponse
    {
        $destination = find_destination_for_current_team($destination_uuid);
        if (! $destination) {
            return redirect()->route('destination.index');
        }
        if (! $destination instanceof StandaloneDocker) {
            return redirect()->route('destination.show', ['destination_uuid' => $destination->uuid]);
        }

        $groups = [
            $destination->applications,
            $destination->services,
        ];
        foreach (DatabaseEngineRegistry::relationNames() as $relationName) {
            $groups[] = $destination->{$relationName};
        }

        $resources = [];
        foreach ($groups as $group) {
            foreach ($group as $resource) {
                $type = match (true) {
                    $resource instanceof Application => 'application',
                    $resource instanceof Service => 'service',
                    default => 'database',
                };
                $environment = $resource->environment;
                $project = $environment?->project;
                $routeName = "project.{$type}.configuration";
                $url = $project
                    ? route($routeName, [
                        'project_uuid' => $project->uuid,
                        'environment_uuid' => $environment->uuid,
                        "{$type}_uuid" => $resource->uuid,
                    ])
                    : null;

                $resources[] = [
                    'uuid' => $resource->uuid,
                    'type' => $type,
                    'name' => $resource->name,
                    'project' => $project?->name,
                    'environment' => $environment?->name,
                    'url' => $url,
                ];
            }
        }

        return Inertia::render('Destination/Resources', [
            'destination' => [
                'uuid' => $destination->uuid,
                'name' => $destination->name,
            ],
            'resources' => $resources,
            'showUrl' => route('destination.show', ['destination_uuid' => $destination->uuid]),
        ]);
    }

    public function update(Request $request, string $destination_uuid): RedirectResponse
    {
        $destination = find_destination_for_current_team($destination_uuid);
        if (! $destination) {
            return redirect()->route('destination.index');
        }
        $this->authorize('update', $destination);

        $validated = Validator::make($request->all(), [
            'name' => ['string', 'required'],
        ])->validate();

        $destination->update($validated);

        return back()->with('success', 'Destination saved.');
    }

    public function destroy(string $destination_uuid): RedirectResponse
    {
        $destination = find_destination_for_current_team($destination_uuid);
        if (! $destination) {
            return redirect()->route('destination.index');
        }
        $this->authorize('delete', $destination);

        if ($destination->getMorphClass() === StandaloneDocker::class) {
            if ($destination->attachedTo()) {
                return back()->with('error', 'You must delete all resources before deleting this destination.');
            }
            $safeNetwork = escapeshellarg($destination->network);
            instant_remote_process(["docker network disconnect {$safeNetwork} coolify-proxy"], $destination->server, throwError: false);
            instant_remote_process(["docker network rm -f {$safeNetwork}"], $destination->server);
        }
        $destination->delete();

        return redirect()->route('destination.index');
    }
}
