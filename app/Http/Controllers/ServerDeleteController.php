<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Server\DeleteServer;
use App\Jobs\DeleteResourceJob;
use App\Models\Server;
use App\Support\ServerChromeData;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class ServerDeleteController extends Controller
{
    use AuthorizesRequests;

    public function index(string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        $checkboxes = [];
        if ($server->hasDefinedResources()) {
            $checkboxes[] = [
                'id' => 'force_delete_resources',
                'label' => 'Delete all resources ('.$server->definedResources()->count().' total)',
            ];
        }

        return Inertia::render('Server/Delete', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'sidebar' => ServerChromeData::sidebar($server, 'main', 'danger'),
            'server' => ['id' => $server->id, 'name' => $server->name],
            'hasResources' => $server->hasDefinedResources(),
            'checkboxes' => $checkboxes,
            'destroyUrl' => route('server.delete.destroy', ['server_uuid' => $server->uuid]),
        ]);
    }

    public function destroy(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        $validated = Validator::make($request->all(), [
            'password' => ['required', 'string'],
            'selected_actions' => ['nullable', 'array'],
        ])->validate();

        if (! verifyPasswordConfirmation($validated['password'])) {
            return back()->with('error', 'The provided password is incorrect.');
        }

        $this->authorize('delete', $server);

        $selectedActions = $validated['selected_actions'] ?? [];
        $forceDeleteResources = in_array('force_delete_resources', $selectedActions, true);

        if ($server->hasDefinedResources() && ! $forceDeleteResources) {
            return back()->with('error', 'Server has defined resources. Please delete them first or select "Delete all resources".');
        }

        if ($forceDeleteResources) {
            foreach ($server->definedResources() as $resource) {
                DeleteResourceJob::dispatch($resource);
            }
        }

        $serverId = $server->id;

        $server->delete();
        DeleteServer::dispatch($serverId);

        return redirect()->route('server.index');
    }
}
