<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Server\CheckUpdates;
use App\Actions\Server\UpdatePackage;
use App\Events\ServerPackageUpdated;
use App\Models\Server;
use App\Notifications\Server\ServerPatchCheck;
use App\Support\ServerChromeData;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class ServerSecurityPatchesController extends Controller
{
    use AuthorizesRequests;

    public function index(string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('viewSecurity', $server);

        return Inertia::render('Server/Security/Patches', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'sidebar' => ServerChromeData::sidebar($server, 'security', 'patches'),
            'isDev' => isDev(),
            'checkUpdatesUrl' => route('server.security.patches.check-updates', ['server_uuid' => $server->uuid]),
            'updateAllUrl' => route('server.security.patches.update-all', ['server_uuid' => $server->uuid]),
            'updatePackageUrl' => route('server.security.patches.update-package', ['server_uuid' => $server->uuid]),
            'notifyUpdatedUrl' => route('server.security.patches.notify-updated', ['server_uuid' => $server->uuid]),
            'sendTestEmailUrl' => route('server.security.patches.send-test-email', ['server_uuid' => $server->uuid]),
        ]);
    }

    public function checkUpdates(string $server_uuid): JsonResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('viewSecurity', $server);

        $job = CheckUpdates::run($server);

        if (isset($job['error'])) {
            return response()->json(['error' => data_get($job, 'error', 'Something went wrong.')]);
        }

        return response()->json([
            'totalUpdates' => data_get($job, 'total_updates', 0),
            'updates' => data_get($job, 'updates', []),
            'osId' => data_get($job, 'osId'),
            'packageManager' => data_get($job, 'package_manager'),
        ]);
    }

    public function updateAll(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $validated = Validator::make($request->all(), [
            'packageManager' => ['required', 'string'],
            'osId' => ['required', 'string'],
        ])->validate();

        try {
            $activity = UpdatePackage::run(
                server: $server,
                packageManager: $validated['packageManager'],
                osId: $validated['osId'],
                all: true
            );
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        if (is_array($activity)) {
            return back()->with('error', data_get($activity, 'error', 'Something went wrong.'));
        }

        return back()->with(['activityId' => $activity->id, 'activityContext' => 'patches-update']);
    }

    public function updatePackage(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $validated = Validator::make($request->all(), [
            'package' => ['required', 'string'],
            'packageManager' => ['required', 'string'],
            'osId' => ['required', 'string'],
        ])->validate();

        try {
            $activity = UpdatePackage::run(
                server: $server,
                packageManager: $validated['packageManager'],
                osId: $validated['osId'],
                package: $validated['package']
            );
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        if (is_array($activity)) {
            return back()->with('error', data_get($activity, 'error', 'Something went wrong.'));
        }

        return back()->with(['activityId' => $activity->id, 'activityContext' => 'patches-update']);
    }

    /**
     * Called by the client once ActivityLog.jsx observes the update activity has finished —
     * mirrors ActivityMonitor::polling()'s "dispatch the given broadcast event class once the
     * exit code is known" behavior, kept here (rather than made generic in ActivityController)
     * since blindly dispatching a client-supplied class name would be a real injection risk.
     */
    public function notifyUpdated(string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('viewSecurity', $server);

        ServerPackageUpdated::dispatch($server->team_id);

        return back();
    }

    public function sendTestEmail(string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('viewSecurity', $server);

        if (! isDev()) {
            return back()->with('error', 'Test email functionality is only available in development mode.');
        }

        $testPatchData = [
            'total_updates' => 8,
            'updates' => [
                ['package' => 'docker-ce', 'current_version' => '24.0.7-1', 'new_version' => '25.0.1-1'],
                ['package' => 'nginx', 'current_version' => '1.20.2-1', 'new_version' => '1.22.1-1'],
                ['package' => 'kernel-generic', 'current_version' => '5.15.0-89', 'new_version' => '5.15.0-91'],
                ['package' => 'openssh-server', 'current_version' => '8.9p1-3', 'new_version' => '9.0p1-1'],
                ['package' => 'curl', 'current_version' => '7.81.0-1', 'new_version' => '7.85.0-1'],
                ['package' => 'git', 'current_version' => '2.34.1-1', 'new_version' => '2.39.1-1'],
                ['package' => 'python3', 'current_version' => '3.10.6-1', 'new_version' => '3.11.0-1'],
                ['package' => 'htop', 'current_version' => '3.2.1-1', 'new_version' => '3.2.2-1'],
            ],
            'osId' => 'ubuntu',
            'package_manager' => 'apt',
        ];

        try {
            $server->team->notify(new ServerPatchCheck($server, $testPatchData));
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to send test email: '.$e->getMessage());
        }

        return back()->with('success', 'Test email sent successfully! Check your email inbox.');
    }
}
