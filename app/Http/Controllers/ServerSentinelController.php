<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Server\StartSentinel;
use App\Actions\Server\StopSentinel;
use App\Models\Server;
use App\Support\ServerChromeData;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class ServerSentinelController extends Controller
{
    use AuthorizesRequests;

    public function index(string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        return Inertia::render('Server/Sentinel', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'sidebar' => ServerChromeData::sidebar($server, 'sentinel', 'configuration'),
            'canUpdate' => auth()->user()?->can('update', $server) ?? false,
            'isDev' => isDev(),
            'isFunctional' => $server->isFunctional(),
            'isSentinelEnabled' => (bool) $server->settings->is_sentinel_enabled,
            'isSentinelLive' => $server->isSentinelLive(),
            'isSentinelDebugEnabled' => (bool) $server->settings->is_sentinel_debug_enabled,
            'sentinelToken' => $server->settings->sentinel_token,
            'sentinelCustomUrl' => $server->settings->sentinel_custom_url,
            'sentinelMetricsRefreshRateSeconds' => $server->settings->sentinel_metrics_refresh_rate_seconds,
            'sentinelMetricsHistoryDays' => $server->settings->sentinel_metrics_history_days,
            'sentinelPushIntervalSeconds' => $server->settings->sentinel_push_interval_seconds,
            'submitUrl' => route('server.sentinel.submit', ['server_uuid' => $server->uuid]),
            'toggleUrl' => route('server.sentinel.toggle', ['server_uuid' => $server->uuid]),
            'restartUrl' => route('server.sentinel.restart', ['server_uuid' => $server->uuid]),
            'regenerateTokenUrl' => route('server.sentinel.regenerate-token', ['server_uuid' => $server->uuid]),
        ]);
    }

    public function submit(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $rules = [
            'sentinelToken' => ['required', 'string', 'max:500', 'regex:/\A[a-zA-Z0-9._\-+=\/]+\z/'],
            'sentinelMetricsRefreshRateSeconds' => ['required', 'integer', 'min:1'],
            'sentinelMetricsHistoryDays' => ['required', 'integer', 'min:1'],
            'sentinelPushIntervalSeconds' => ['required', 'integer', 'min:10'],
            'sentinelCustomUrl' => ['nullable', 'url'],
        ];

        if (isDev()) {
            $rules['isSentinelDebugEnabled'] = ['sometimes', 'boolean'];
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $validated = $validator->validated();

        try {
            $server->settings->sentinel_token = $validated['sentinelToken'];
            $server->settings->sentinel_metrics_refresh_rate_seconds = $validated['sentinelMetricsRefreshRateSeconds'];
            $server->settings->sentinel_metrics_history_days = $validated['sentinelMetricsHistoryDays'];
            $server->settings->sentinel_push_interval_seconds = $validated['sentinelPushIntervalSeconds'];
            $server->settings->sentinel_custom_url = $validated['sentinelCustomUrl'] ?? null;
            if (isDev()) {
                $server->settings->is_sentinel_debug_enabled = $request->boolean('isSentinelDebugEnabled');
            }
            $server->settings->save();

            return back()->with('success', 'Sentinel settings updated.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function toggle(string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('manageSentinel', $server);

        try {
            if (! $server->settings->is_sentinel_enabled) {
                if ($server->isBuildServer()) {
                    return back()->with('error', 'Sentinel cannot be enabled on build servers.');
                }

                $server->settings->is_sentinel_enabled = true;
                $server->settings->save();
                $customImage = isDev() ? (request()->string('sentinelCustomDockerImage')->toString() ?: null) : null;
                StartSentinel::run($server, true, null, $customImage);

                return back()->with('info', 'Restarting Sentinel.');
            }

            $server->settings->is_sentinel_enabled = false;
            $server->settings->is_metrics_enabled = false;
            $server->settings->is_sentinel_debug_enabled = false;
            $server->settings->save();
            StopSentinel::dispatch($server);

            return back()->with('info', 'Restarting Sentinel.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function restart(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('manageSentinel', $server);

        try {
            $customImage = isDev() ? ($request->string('sentinelCustomDockerImage')->toString() ?: null) : null;
            $server->restartSentinel($customImage);

            return back()->with('info', 'Restarting Sentinel.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function regenerateToken(string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('manageSentinel', $server);

        try {
            $server->settings->generateSentinelToken();

            return back()->with('success', 'Token regenerated. Restarting Sentinel.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
