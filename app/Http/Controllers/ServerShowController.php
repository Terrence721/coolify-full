<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Server\StopSentinel;
use App\Events\ServerReachabilityChanged;
use App\Models\Server;
use App\Rules\ValidServerIp;
use App\Services\ServerValidationService;
use App\Support\ServerChromeData;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * React port of App\Livewire\Server\Show — the "General" tab, and the last full-page Livewire
 * component in the whole migration. Two real findings shrank this port's scope from the original:
 *
 * 1. Every Sentinel/metrics-enable property, hook, and the SentinelRestarted Echo listener on the
 *    original are dead UI — confirmed via exhaustive grep, "sentinel" appears zero times in
 *    show.blade.php. That functionality is fully owned by the separate, already-converted
 *    /server/{uuid}/sentinel page. Not ported. Likewise isSwarmManager/isSwarmWorker are carried
 *    through the model but have no checkbox on this page either (that's /server/{uuid}/swarm) —
 *    only isBuildServer is actually editable here.
 * 2. The "Validate Server & Install Docker Engine" flow reuses ServerValidationService (extracted
 *    from BoardingController in this same phase) rather than a third implementation of the same
 *    connection/prerequisites/Docker orchestration.
 */
class ServerShowController extends Controller
{
    use AuthorizesRequests;

    public function index(string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $settings = $server->settings;

        return Inertia::render('Server/Show', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'sidebar' => ServerChromeData::sidebar($server, 'main', 'general'),
            'server' => [
                'id' => $server->id,
                'uuid' => $server->uuid,
                'name' => $server->name,
                'description' => $server->description,
                'ip' => $server->ip,
                'user' => $server->user,
                'port' => (string) $server->port,
                'isLocalhost' => $server->isLocalhost(),
                'isFunctional' => $server->isFunctional(),
                'isForceDisabled' => (bool) $server->isForceDisabled(),
                'isReachable' => (bool) $settings->is_reachable,
                'isUsable' => (bool) $settings->is_usable,
                'isSwarmWorker' => (bool) $settings->is_swarm_worker,
                'isBuildServer' => (bool) $settings->is_build_server,
                'isBuildServerLocked' => ! $server->isEmpty(),
                'isValidating' => (bool) $server->is_validating,
                'validationLogs' => $server->validation_logs,
                'connectionTimeout' => $settings->connection_timeout,
                'wildcardDomain' => $settings->wildcard_domain,
                'serverTimezone' => $settings->server_timezone,
                'hasCloudProviderToken' => (bool) $server->cloudProviderToken,
                'serverMetadata' => $server->server_metadata,
            ],
            'timezones' => collect(timezone_identifiers_list())->sort()->values()->all(),
            'isCloud' => isCloud(),
            'urls' => [
                'update' => route('server.show.update', ['server_uuid' => $server->uuid]),
                'instantSaveBuildServer' => route('server.show.instant-save-build-server', ['server_uuid' => $server->uuid]),
                'checkLocalhost' => route('server.show.check-localhost', ['server_uuid' => $server->uuid]),
                'refreshMetadata' => route('server.show.refresh-metadata', ['server_uuid' => $server->uuid]),
                'validate' => route('server.show.validate', ['server_uuid' => $server->uuid]),
            ],
        ]);
    }

    public function update(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $validated = Validator::make(
            $request->all(),
            [
                'name' => ValidationPatterns::nameRules(),
                'description' => ValidationPatterns::descriptionRules(),
                'ip' => ['required', new ValidServerIp],
                'user' => ValidationPatterns::serverUsernameRules(),
                'port' => 'required|integer|between:1,65535',
                'connectionTimeout' => 'required|integer|min:1|max:300',
                'wildcardDomain' => 'nullable|url',
                'serverTimezone' => 'required|string',
            ],
            array_merge(ValidationPatterns::combinedMessages(), ValidationPatterns::serverUsernameMessages()),
        )->validate();

        $foundServer = Server::query()->where('ip', $validated['ip'])->where('id', '!=', $server->id)->first();
        if ($foundServer) {
            $message = $foundServer->team_id === currentTeam()->id
                ? 'A server with this IP/Domain already exists in your team.'
                : 'A server with this IP/Domain is already in use by another team.';

            return back()->with('error', $message);
        }

        if (! validate_timezone($validated['serverTimezone'])) {
            return back()->with('error', 'Invalid timezone.');
        }

        $server->name = $validated['name'];
        $server->description = $validated['description'] ?? null;
        $server->ip = $validated['ip'];
        $server->user = $validated['user'];
        $server->port = $validated['port'];
        $server->save();

        $server->settings->connection_timeout = $validated['connectionTimeout'];
        $server->settings->wildcard_domain = $validated['wildcardDomain'] ?? null;
        $server->settings->server_timezone = $validated['serverTimezone'];
        $server->settings->save();

        return back()->with('success', 'Server settings updated.');
    }

    public function instantSaveBuildServer(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        // Mirrors the original's #[Locked] Livewire property: once a server has resources, the
        // build-server flag can't flip, server-side, not just a disabled checkbox in the UI.
        if (! $server->isEmpty()) {
            return back()->with('error', "You can't use this server as a build server because it has defined resources.");
        }

        $validated = Validator::make($request->all(), ['isBuildServer' => 'required|boolean'])->validate();

        if ($validated['isBuildServer'] && $server->settings->is_sentinel_enabled) {
            $server->settings->is_sentinel_enabled = false;
            $server->settings->is_metrics_enabled = false;
            $server->settings->is_sentinel_debug_enabled = false;
            StopSentinel::dispatch($server);
        }

        $server->settings->is_build_server = $validated['isBuildServer'];
        $server->settings->save();

        return back()->with('success', 'Server settings updated.');
    }

    public function checkLocalhostConnection(string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        ['uptime' => $uptime, 'error' => $error] = $server->validateConnection();

        if (! $uptime) {
            return back()->with('error', 'Server is not reachable. '.$error);
        }

        $server->settings->is_reachable = true;
        $server->settings->is_usable = true;
        $server->settings->save();
        ServerReachabilityChanged::dispatch($server);

        return back()->with('success', 'Server is reachable.');
    }

    public function refreshServerMetadata(string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $result = $server->gatherServerMetadata();

        return $result
            ? back()->with('success', 'Server details refreshed.')
            : back()->with('error', 'Could not fetch server details. Is the server reachable?');
    }

    public function validateServer(Request $request, string $server_uuid, ServerValidationService $validationService): JsonResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $validated = Validator::make($request->all(), [
            'install' => 'boolean',
            'attempt' => 'integer|min:0',
        ])->validate();

        if (($validated['attempt'] ?? 0) === 0) {
            $server->validation_logs = null;
            $server->is_validating = true;
            $server->save();
        }

        $result = $validationService->validate($server, $validated['install'] ?? true, $validated['attempt'] ?? 0);

        if ($result['status'] !== 'installing') {
            $server->update(['is_validating' => false]);
        }

        return response()->json($result);
    }
}
