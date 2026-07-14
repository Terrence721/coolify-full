<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Jobs\ScheduledTaskJob;
use App\Models\Application;
use App\Models\ScheduledTask;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * React port of the scheduled-tasks tab family — App\Livewire\Project\Shared\ScheduledTask\
 * {All,Add,Show,Executions} — scoped to services (Phase 58). The Application router's usage
 * converts with that router; the Livewire components remain in place for it.
 *
 * Faithful-port notes:
 * - One route pair serves both views: the list (no task_uuid) and the task detail
 *   (/tasks/{task_uuid}), matching the original's two route names on the Livewire shell.
 * - The original Add::submit() fell back to an undefined `$this->subServiceName` property
 *   (always null) when a service task was submitted with an empty container — confirmed-dead
 *   code, not ported: the service Add form is a select defaulting to the first child name,
 *   so the branch was unreachable from the UI.
 * - Execution timestamps are formatted server-side in the server's timezone
 *   (formatDateInServerTimezone), matching the blade.
 * - Task deletion is confirmed client-side by typing the task name (the original modal set
 *   :confirmWithPassword="false"), so the endpoint requires no password — authorization only.
 */
trait ManagesResourceScheduledTasks
{
    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function scheduledTasksTabProps(Application|Service $resource, array $parameters, string $routePrefix, ?string $taskUuid): array
    {
        if ($taskUuid !== null) {
            return $this->scheduledTaskDetailProps($resource, $parameters, $routePrefix, $taskUuid);
        }

        return [
            'tasks' => $resource->scheduled_tasks()->get()->map(fn (ScheduledTask $task) => [
                'uuid' => $task->uuid,
                'name' => $task->name,
                'container' => $task->container,
                'frequency' => $task->frequency,
                'lastRunStatus' => data_get($task->latest_log, 'status', 'No runs yet'),
                'href' => route("{$routePrefix}.scheduled-tasks", [...$parameters, 'task_uuid' => $task->uuid]),
            ])->values(),
            'containerNames' => $this->scheduledTaskContainerNames($resource),
            'taskUrls' => [
                'store' => route("{$routePrefix}.scheduled-tasks.store", $parameters),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function scheduledTaskDetailProps(Application|Service $resource, array $parameters, string $routePrefix, string $taskUuid): array
    {
        $task = $this->resolveOwnedScheduledTask($resource, $taskUuid);
        $server = $task->server();
        $taskParameters = [...$parameters, 'task_uuid' => $task->uuid];

        return [
            'task' => [
                'uuid' => $task->uuid,
                'name' => $task->name,
                'command' => $task->command,
                'frequency' => $task->frequency,
                'container' => $task->container,
                'timeout' => $task->timeout ?? 300,
                'enabled' => $task->enabled,
            ],
            'isResourceRunning' => $resource->isRunning(),
            'executions' => $task->executions()->take(20)->get()->map(fn ($execution) => [
                'id' => $execution->id,
                'status' => $execution->status,
                'message' => $execution->message,
                'createdAt' => formatDateInServerTimezone($execution->created_at ?? now(), $server),
                'finishedAt' => $execution->finished_at ? formatDateInServerTimezone($execution->finished_at, $server) : null,
                'duration' => calculateDuration($execution->created_at, $execution->finished_at),
                'finishedAgo' => $execution->finished_at ? Carbon::parse($execution->finished_at)->diffForHumans() : null,
                'downloadUrl' => filled($execution->message)
                    ? route("{$routePrefix}.scheduled-tasks.download", [...$taskParameters, 'execution_id' => $execution->id])
                    : null,
            ])->values(),
            'taskUrls' => [
                'update' => route("{$routePrefix}.scheduled-tasks.update", $taskParameters),
                'toggle' => route("{$routePrefix}.scheduled-tasks.toggle", $taskParameters),
                'execute' => route("{$routePrefix}.scheduled-tasks.execute", $taskParameters),
                'destroy' => route("{$routePrefix}.scheduled-tasks.destroy", $taskParameters),
                'index' => route("{$routePrefix}.scheduled-tasks.show", $parameters),
            ],
        ];
    }

    public function storeScheduledTask(Request $request, Application|Service $resource): RedirectResponse
    {
        $this->authorize('update', $resource);

        $validated = Validator::make($request->all(), [
            'name' => 'required|string',
            'command' => 'required|string',
            'frequency' => 'required|string',
            'container' => 'nullable|string',
            'timeout' => 'required|integer|min:60|max:36000',
        ])->validate();

        if (! validate_cron_expression($validated['frequency'])) {
            return back()->with('error', 'Invalid Cron / Human expression.');
        }

        $task = new ScheduledTask;
        $task->name = $validated['name'];
        $task->command = $validated['command'];
        $task->frequency = $validated['frequency'];
        $task->container = $validated['container'] ?? null;
        $task->timeout = (int) $validated['timeout'];
        $task->team_id = currentTeam()->id;
        if ($resource instanceof Service) {
            $task->service_id = $resource->id;
        } else {
            $task->application_id = $resource->id;
        }
        $task->save();

        return back()->with('success', 'Scheduled task added.');
    }

    public function updateScheduledTask(Request $request, Application|Service $resource, ScheduledTask $task): RedirectResponse
    {
        $this->authorize('update', $resource);

        $validated = Validator::make($request->all(), [
            'enabled' => 'boolean',
            'name' => 'required|string',
            'command' => 'required|string',
            'frequency' => 'required|string',
            'container' => 'nullable|string',
            'timeout' => 'required|integer|min:60|max:36000',
        ])->validate();

        if (! validate_cron_expression($validated['frequency'])) {
            return back()->with('error', 'Invalid Cron / Human expression.');
        }

        $task->enabled = $request->boolean('enabled', $task->enabled);
        $task->name = str($validated['name'])->trim()->value();
        $task->command = str($validated['command'])->trim()->value();
        $task->frequency = str($validated['frequency'])->trim()->value();
        $task->container = str($validated['container'] ?? '')->trim()->value();
        $task->timeout = (int) $validated['timeout'];
        $task->save();

        return back()->with('success', 'Scheduled task updated.');
    }

    public function toggleScheduledTask(Application|Service $resource, ScheduledTask $task): RedirectResponse
    {
        $this->authorize('update', $resource);

        $task->enabled = ! $task->enabled;
        $task->save();

        return back()->with('success', $task->enabled ? 'Scheduled task enabled.' : 'Scheduled task disabled.');
    }

    public function executeScheduledTaskNow(Application|Service $resource, ScheduledTask $task): RedirectResponse
    {
        $this->authorize('update', $resource);

        ScheduledTaskJob::dispatch($task);

        return back()->with('success', 'Scheduled task executed.');
    }

    /**
     * @param  array<string, string>  $parameters
     */
    public function destroyScheduledTask(Application|Service $resource, ScheduledTask $task, string $routePrefix, array $parameters): RedirectResponse
    {
        $this->authorize('update', $resource);

        $task->delete();

        return redirect()->route("{$routePrefix}.scheduled-tasks.show", $parameters)
            ->with('success', 'Scheduled task deleted.');
    }

    public function downloadScheduledTaskLogs(ScheduledTask $task, int $executionId): StreamedResponse
    {
        $execution = $task->executions()->findOrFail($executionId);

        return response()->streamDownload(function () use ($execution) {
            echo $execution->message;
        }, 'task-execution-'.$execution->id.'.log');
    }

    private function resolveOwnedScheduledTask(Application|Service $resource, string $taskUuid): ScheduledTask
    {
        return $resource->scheduled_tasks()->where('uuid', $taskUuid)->firstOrFail();
    }

    /**
     * The container-name candidates for the Add form — the original All::mount() branch:
     * a service lists its child application/database names; a docker-compose application
     * lists its parsed compose service keys; anything else has no candidates.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function scheduledTaskContainerNames(Application|Service $resource): \Illuminate\Support\Collection
    {
        if ($resource instanceof Service) {
            return $resource->applications()->pluck('name')
                ->merge($resource->databases()->pluck('name'))
                ->values();
        }

        if ($resource->build_pack === 'dockercompose') {
            /** @var array<string, mixed>|\Illuminate\Support\Collection<string, mixed>|null $services */
            $services = data_get($resource->parse(), 'services');

            return collect($services)->keys();
        }

        return collect();
    }
}
