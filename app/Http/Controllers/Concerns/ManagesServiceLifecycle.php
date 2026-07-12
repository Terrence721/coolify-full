<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Actions\Docker\GetContainersStatus;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Enums\ProcessStatus;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * Service start/stop/restart/force-deploy/check-status actions, ported from
 * App\Livewire\Project\Service\Heading. Extracted so both
 * ProjectServiceDatabaseBackupController (Phase 45, backup-scoped routes) and
 * ProjectLogsController (Phase 47, service-scoped routes with no backup context) can
 * trigger the same lifecycle actions against an already-resolved Service.
 */
trait ManagesServiceLifecycle
{
    private function startService(Service $service): RedirectResponse
    {
        $this->authorize('deploy', $service);

        $activity = StartService::run($service, pullLatestImages: true);

        return back()->with(['activityId' => $activity->id, 'activityContext' => 'service']);
    }

    private function forceDeployService(Service $service): RedirectResponse
    {
        $this->authorize('deploy', $service);

        $inProgressStatuses = [ProcessStatus::IN_PROGRESS->value, ProcessStatus::QUEUED->value];
        Activity::where('properties->type_uuid', $service->uuid)
            ->whereIn('properties->status', $inProgressStatuses)
            ->get()
            ->each(function (Activity $activity) {
                $activity->properties->status = ProcessStatus::ERROR->value;
                $activity->save();
            });

        $activity = StartService::run($service, pullLatestImages: true, stopBeforeStart: true);

        return back()->with(['activityId' => $activity->id, 'activityContext' => 'service']);
    }

    private function restartService(Service $service): RedirectResponse
    {
        $this->authorize('deploy', $service);

        if ($this->isServiceDeploymentInProgress($service)) {
            return back()->with('error', 'There is a deployment in progress.');
        }

        $activity = StartService::run($service, stopBeforeStart: true);

        return back()->with(['activityId' => $activity->id, 'activityContext' => 'service']);
    }

    private function stopService(Request $request, Service $service): RedirectResponse
    {
        $this->authorize('stop', $service);

        StopService::dispatch($service, false, $request->boolean('docker_cleanup', true));

        return back()->with('info', 'Gracefully stopping service. It could take a while depending on the service.');
    }

    private function checkServiceStatus(Service $service): RedirectResponse
    {
        if (! $service->server->isFunctional()) {
            return back()->with('error', 'Server is not functional.');
        }

        GetContainersStatus::dispatch($service->server);

        return back();
    }

    private function isServiceDeploymentInProgress(Service $service): bool
    {
        $activity = Activity::where('properties->type_uuid', $service->uuid)->latest()->first();
        $status = data_get($activity, 'properties.status');

        return $status === ProcessStatus::QUEUED->value || $status === ProcessStatus::IN_PROGRESS->value;
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceConfigurationCheckerProps(Service $service): array
    {
        return [
            'isConfigurationChanged' => $service->isConfigurationChanged(),
            'isExited' => str($service->status)->contains('exited'),
            'configHash' => $service->config_hash,
            'diff' => [],
        ];
    }
}
