<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Server;
use App\Support\ServerChromeData;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class ServerResourcesController extends Controller
{
    use AuthorizesRequests;

    public function index(string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        return Inertia::render('Server/Resources', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'managedResources' => $server->definedResources()->sortBy('name', SORT_NATURAL)->values()->map(function ($resource) {
                $status = (string) $resource->status;
                $displayStatus = $resource->type() === 'service' ? formatContainerStatus($status) : $status;

                return [
                    'uuid' => $resource->uuid,
                    'name' => $resource->name,
                    'projectName' => data_get($resource->project(), 'name'),
                    'environmentName' => data_get($resource, 'environment.name'),
                    'type' => str($resource->type())->headline()->toString(),
                    'status' => $displayStatus,
                    'statusCategory' => $this->statusCategory($displayStatus),
                    'link' => $resource->link(),
                ];
            })->values(),
            'unmanagedContainers' => Inertia::defer(fn () => $server->loadUnmanagedContainers()
                ->sortBy('name', SORT_NATURAL)
                ->values()
                ->map(fn ($container) => [
                    'id' => data_get($container, 'ID'),
                    'name' => data_get($container, 'Names'),
                    'image' => data_get($container, 'Image'),
                    'state' => data_get($container, 'State'),
                ])),
            'containerActionUrl' => route('server.resources.container-action', ['server_uuid' => $server->uuid]),
        ]);
    }

    public function containerAction(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $validated = Validator::make($request->all(), [
            'id' => ['required', 'string'],
            'action' => ['required', 'string', 'in:start,restart,stop'],
        ])->validate();

        if (! ValidationPatterns::isValidContainerName($validated['id'])) {
            return back()->with('error', 'Invalid container identifier.');
        }

        match ((string) $validated['action']) {
            'start' => $server->startUnmanaged($validated['id']),
            'restart' => $server->restartUnmanaged($validated['id']),
            'stop' => $server->stopUnmanaged($validated['id']),
            default => throw new \LogicException('Unreachable: validated against in:start,restart,stop.'),
        };

        return back()->with('success', "Container {$validated['action']}ed.");
    }

    private function statusCategory(string $status): string
    {
        $status = str($status)->lower();

        if ($status->startsWith('running') || $status->contains('running')) {
            return 'running';
        }
        if ($status->startsWith('degraded') || $status->contains('degraded')) {
            return 'degraded';
        }
        if ($status->startsWith('restarting') || $status->startsWith('starting') || $status->contains('restarting') || $status->contains('starting')) {
            return 'restarting';
        }

        return 'stopped';
    }
}
