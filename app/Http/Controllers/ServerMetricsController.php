<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Server\StartSentinel;
use App\Models\Server;
use App\Support\ServerChromeData;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ServerMetricsController extends Controller
{
    use AuthorizesRequests;

    public function index(string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        return Inertia::render('Server/Metrics', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'sidebar' => ServerChromeData::sidebar($server, 'main', 'metrics'),
            'canUpdate' => auth()->user()?->can('update', $server) ?? false,
            'isMetricsEnabled' => (bool) $server->isMetricsEnabled(),
            'isSentinelEnabled' => (bool) $server->isSentinelEnabled(),
            'sentinelUrl' => route('server.sentinel', ['server_uuid' => $server->uuid]),
            'toggleUrl' => route('server.metrics.toggle', ['server_uuid' => $server->uuid]),
            'dataUrl' => route('server.metrics.data', ['server_uuid' => $server->uuid]),
        ]);
    }

    public function toggleMetrics(string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        try {
            $server->settings->is_metrics_enabled = ! $server->settings->is_metrics_enabled;
            $server->settings->save();
            $server->refresh();

            if ($server->isMetricsEnabled()) {
                StartSentinel::run($server, true);

                return back()->with('success', 'Metrics enabled. Starting Sentinel.');
            }

            $server->restartSentinel();

            return back()->with('success', 'Metrics disabled. Restarting Sentinel.');
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in toggleMetrics().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }
    }

    public function data(Request $request, string $server_uuid): JsonResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        $interval = (int) $request->query('interval', 5);

        try {
            return response()->json([
                'cpu' => $server->getCpuMetrics($interval),
                'memory' => $server->getMemoryMetrics($interval),
            ]);
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in data().', ['error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
