<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Server;
use App\Support\ServerChromeData;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class ServerAdvancedController extends Controller
{
    use AuthorizesRequests;

    public function index(string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $settings = $server->settings;

        return Inertia::render('Server/Advanced', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'sidebar' => ServerChromeData::sidebar($server, 'main', 'advanced'),
            'concurrentBuilds' => $settings->concurrent_builds,
            'dynamicTimeout' => $settings->dynamic_timeout,
            'deploymentQueueLimit' => $settings->deployment_queue_limit,
            'serverDiskUsageNotificationThreshold' => $settings->server_disk_usage_notification_threshold,
            'serverDiskUsageCheckFrequency' => $settings->server_disk_usage_check_frequency,
            'updateUrl' => route('server.advanced.update', ['server_uuid' => $server->uuid]),
        ]);
    }

    public function update(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $validated = Validator::make($request->all(), [
            'serverDiskUsageCheckFrequency' => ['required', 'string'],
            'serverDiskUsageNotificationThreshold' => ['required', 'integer', 'min:1', 'max:99'],
            'concurrentBuilds' => ['required', 'integer', 'min:1'],
            'dynamicTimeout' => ['required', 'integer', 'min:1'],
            'deploymentQueueLimit' => ['required', 'integer', 'min:1'],
        ])->validate();

        try {
            $isValidFrequency = validate_cron_expression($validated['serverDiskUsageCheckFrequency']);
        } catch (\Throwable) {
            $isValidFrequency = false;
        }
        if (! $isValidFrequency) {
            return back()->with('error', 'Invalid Cron / Human expression for Disk Usage Check Frequency.');
        }

        $settings = $server->settings;
        $settings->concurrent_builds = $validated['concurrentBuilds'];
        $settings->dynamic_timeout = $validated['dynamicTimeout'];
        $settings->deployment_queue_limit = $validated['deploymentQueueLimit'];
        $settings->server_disk_usage_notification_threshold = $validated['serverDiskUsageNotificationThreshold'];
        $settings->server_disk_usage_check_frequency = $validated['serverDiskUsageCheckFrequency'];
        $settings->save();

        return back()->with('success', 'Server updated.');
    }
}
