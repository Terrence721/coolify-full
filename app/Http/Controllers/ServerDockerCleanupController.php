<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\DockerCleanupJob;
use App\Models\DockerCleanupExecution;
use App\Models\Server;
use App\Support\ServerChromeData;
use Cron\CronExpression;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServerDockerCleanupController extends Controller
{
    use AuthorizesRequests;

    public function index(string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $settings = $server->settings;

        return Inertia::render('Server/DockerCleanup', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'sidebar' => ServerChromeData::sidebar($server, 'main', 'docker-cleanup'),
            'canUpdate' => auth()->user()?->can('update', $server) ?? false,
            'isCloud' => isCloud(),
            'settings' => [
                'dockerCleanupFrequency' => $settings->docker_cleanup_frequency,
                'dockerCleanupThreshold' => $settings->docker_cleanup_threshold,
                'forceDockerCleanup' => $settings->force_docker_cleanup,
                'deleteUnusedVolumes' => $settings->delete_unused_volumes,
                'deleteUnusedNetworks' => $settings->delete_unused_networks,
                'disableApplicationImageRetention' => $settings->disable_application_image_retention,
            ],
            'isCleanupStale' => $this->isCleanupStale($server),
            'lastExecutionTime' => $this->lastExecution($server)?->created_at?->diffForHumans(),
            'isSchedulerHealthy' => Cache::get('scheduled-job-manager:heartbeat') !== null,
            'executions' => $this->serializeExecutions($server),
            'updateUrl' => route('server.docker-cleanup.update', ['server_uuid' => $server->uuid]),
            'manualCleanupUrl' => route('server.docker-cleanup.manual-cleanup', ['server_uuid' => $server->uuid]),
            'executionsUrl' => route('server.docker-cleanup.executions', ['server_uuid' => $server->uuid]),
        ]);
    }

    public function update(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $validated = Validator::make($request->all(), [
            'dockerCleanupFrequency' => ['required', 'string'],
            'dockerCleanupThreshold' => ['required', 'integer', 'min:1', 'max:99'],
            'forceDockerCleanup' => ['required', 'boolean'],
            'deleteUnusedVolumes' => ['required', 'boolean'],
            'deleteUnusedNetworks' => ['required', 'boolean'],
            'disableApplicationImageRetention' => ['required', 'boolean'],
        ])->validate();

        if (! validate_cron_expression($validated['dockerCleanupFrequency'])) {
            return back()->with('error', 'Invalid Cron / Human expression for Docker Cleanup Frequency.');
        }

        $settings = $server->settings;
        $settings->docker_cleanup_frequency = $validated['dockerCleanupFrequency'];
        $settings->docker_cleanup_threshold = $validated['dockerCleanupThreshold'];
        $settings->force_docker_cleanup = $validated['forceDockerCleanup'];
        $settings->delete_unused_volumes = $validated['deleteUnusedVolumes'];
        $settings->delete_unused_networks = $validated['deleteUnusedNetworks'];
        $settings->disable_application_image_retention = $validated['disableApplicationImageRetention'];
        $settings->save();

        return back()->with('success', 'Server updated.');
    }

    public function manualCleanup(string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        DockerCleanupJob::dispatch($server, true, $server->settings->delete_unused_volumes, $server->settings->delete_unused_networks);

        return back()->with('success', 'Manual cleanup job started. Depending on the amount of data, this might take a while.');
    }

    public function executions(string $server_uuid): JsonResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        return response()->json([
            'executions' => $this->serializeExecutions($server),
        ]);
    }

    public function downloadLog(string $server_uuid, int $execution): StreamedResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $executionModel = DockerCleanupExecution::where('server_id', $server->id)->findOrFail($execution);

        return response()->streamDownload(function () use ($executionModel) {
            echo $executionModel->message;
        }, "docker-cleanup-{$executionModel->uuid}.log");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeExecutions(Server $server): array
    {
        return $server->dockerCleanupExecutions()
            ->orderBy('created_at', 'desc')
            ->take(20)
            ->get()
            ->map(fn (DockerCleanupExecution $execution) => [
                'id' => $execution->id,
                'status' => $execution->status,
                'message' => $execution->message,
                'cleanupLog' => $execution->cleanup_log ? json_decode($execution->cleanup_log, true) : null,
                'startedAt' => formatDateInServerTimezone($execution->created_at, $server),
                'finishedAt' => $execution->finished_at ? formatDateInServerTimezone($execution->finished_at, $server) : null,
                'duration' => $execution->finished_at ? calculateDuration($execution->created_at, $execution->finished_at) : null,
                'finishedHuman' => $execution->finished_at ? Carbon::parse($execution->finished_at)->diffForHumans() : null,
                'downloadUrl' => route('server.docker-cleanup.download-log', ['server_uuid' => $server->uuid, 'execution' => $execution->id]),
            ])
            ->all();
    }

    private function lastExecution(Server $server): ?DockerCleanupExecution
    {
        return DockerCleanupExecution::where('server_id', $server->id)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    private function isCleanupStale(Server $server): bool
    {
        try {
            $lastExecution = $this->lastExecution($server);

            if (! $lastExecution) {
                return false;
            }

            $frequency = $server->settings->docker_cleanup_frequency ?? '0 0 * * *';
            if (isset(VALID_CRON_STRINGS[$frequency])) {
                $frequency = VALID_CRON_STRINGS[$frequency];
            }

            $cron = new CronExpression($frequency);
            $now = Carbon::now();
            $nextRun = Carbon::parse($cron->getNextRunDate($now));
            $afterThat = Carbon::parse($cron->getNextRunDate($nextRun));
            $intervalMinutes = $nextRun->diffInMinutes($afterThat);

            $threshold = max($intervalMinutes * 2, 10);

            return Carbon::parse($lastExecution->created_at)->diffInMinutes($now) > $threshold;
        } catch (\Throwable) {
            return false;
        }
    }
}
