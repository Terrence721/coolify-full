<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Docker\GetContainersStatus;
use App\Contracts\StandaloneDatabaseInstance;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Support\DatabaseEngineRegistry;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class ProjectDatabaseBackupController extends Controller
{
    use AuthorizesRequests;

    public function index(string $project_uuid, string $environment_uuid, string $database_uuid): Response|RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid);
        if (! $database instanceof Model) {
            return $database;
        }

        if (! (DatabaseEngineRegistry::forInstance($database)?->supportsBackup ?? false)) {
            return redirect()->route('project.database.configuration', compact('project_uuid', 'environment_uuid', 'database_uuid'));
        }

        $parameters = compact('project_uuid', 'environment_uuid', 'database_uuid');

        return Inertia::render('Project/Database/Backup/Index', [
            'database' => [
                'uuid' => $database->uuid,
                'name' => $database->name,
                'status' => $database->status,
            ],
            'heading' => $this->headingProps($database, $parameters),
            'configurationChecker' => $this->configurationCheckerProps($database),
            'scheduledBackups' => $database->scheduledBackups->sortByDesc('created_at')->values()->map(
                fn (ScheduledDatabaseBackup $backup) => $this->backupProps($backup, $parameters)
            ),
            's3Storages' => currentTeam()->s3s->map(fn (S3Storage $s3) => ['id' => $s3->id, 'name' => $s3->name])->values(),
            'canUpdate' => auth()->user()?->can('update', $database) ?? false,
            'urls' => [
                'store' => route('project.database.backup.store', $parameters),
                'start' => route('project.database.start', $parameters),
                'stop' => route('project.database.stop', $parameters),
                'restart' => route('project.database.restart', $parameters),
                'checkStatus' => route('project.database.check-status', $parameters),
            ],
        ]);
    }

    public function store(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof Model) {
            return $database;
        }

        $this->authorize('manageBackups', $database);

        $validated = Validator::make($request->all(), [
            'frequency' => 'required|string',
            'save_to_s3' => 'required|boolean',
            's3_storage_id' => 'nullable|integer',
        ])->validate();

        if ($validated['save_to_s3']) {
            $s3StorageExists = ! is_null($validated['s3_storage_id'] ?? null)
                && S3Storage::where('team_id', currentTeam()->id)
                    ->where('is_usable', true)
                    ->whereKey($validated['s3_storage_id'])
                    ->exists();

            if (! $s3StorageExists) {
                return back()->with('error', 'Please select a valid S3 storage to enable S3 backups.');
            }
        }

        if (! validate_cron_expression($validated['frequency'])) {
            return back()->with('error', 'Invalid Cron / Human expression.');
        }

        $payload = [
            'enabled' => true,
            'frequency' => $validated['frequency'],
            'save_s3' => $validated['save_to_s3'],
            's3_storage_id' => $validated['s3_storage_id'] ?? null,
            'database_id' => $database->id,
            'database_type' => $database->getMorphClass(),
            'team_id' => currentTeam()->id,
        ];

        if ($database->type() === 'standalone-postgresql') {
            $payload['databases_to_backup'] = $database->postgres_db;
        } elseif ($database->type() === 'standalone-mysql') {
            $payload['databases_to_backup'] = $database->mysql_database;
        } elseif ($database->type() === 'standalone-mariadb') {
            $payload['databases_to_backup'] = $database->mariadb_database;
        }

        ScheduledDatabaseBackup::create($payload);

        return back()->with('success', 'Scheduled backup created.');
    }

    public function start(string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof Model) {
            return $database;
        }

        $this->authorize('manage', $database);

        $activity = StartDatabase::run($database);
        if (! $activity instanceof Activity) {
            return back()->with('error', is_string($activity) ? $activity : 'Failed to start database.');
        }

        return back()->with(['activityId' => $activity->id, 'activityContext' => 'database']);
    }

    public function restart(string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof Model) {
            return $database;
        }

        $this->authorize('manage', $database);

        $activity = RestartDatabase::run($database);
        if (! $activity instanceof Activity) {
            return back()->with('error', is_string($activity) ? $activity : 'Failed to restart database.');
        }

        return back()->with(['activityId' => $activity->id, 'activityContext' => 'database']);
    }

    public function stop(Request $request, string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof Model) {
            return $database;
        }

        $this->authorize('manage', $database);

        StopDatabase::dispatch($database, false, $request->boolean('docker_cleanup', true));

        return back()->with('info', 'Gracefully stopping database.');
    }

    public function checkStatus(string $project_uuid, string $environment_uuid, string $database_uuid): RedirectResponse
    {
        $database = $this->resolveDatabase($project_uuid, $environment_uuid, $database_uuid, redirectOnMissing: true);
        if (! $database instanceof Model) {
            return $database;
        }

        if (! $database->destination->server->isFunctional()) {
            return back()->with('error', 'Server is not functional.');
        }

        GetContainersStatus::dispatch($database->destination->server);

        return back();
    }

    /**
     * @return (Model&StandaloneDatabaseInstance)|RedirectResponse
     */
    private function resolveDatabase(string $project_uuid, string $environment_uuid, string $database_uuid, bool $redirectOnMissing = false): Model|RedirectResponse
    {
        $project = currentTeam()->load(['projects'])->projects->where('uuid', $project_uuid)->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('uuid', $environment_uuid)->first();
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        $database = $environment->databases()->where('uuid', $database_uuid)->first();
        if (! $database) {
            return redirect()->route('dashboard');
        }

        return $database;
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function headingProps(Model $database, array $parameters): array
    {
        return [
            'parameters' => $parameters,
            'dockerCleanupDefault' => true,
            'isFunctional' => (bool) $database->destination?->server?->isFunctional(),
            'isExited' => str($database->status)->startsWith('exited'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function configurationCheckerProps(Model $database): array
    {
        return [
            'isConfigurationChanged' => $database->isConfigurationChanged(),
            'isExited' => str($database->status)->startsWith('exited'),
            'configHash' => $database->config_hash,
            'diff' => [],
        ];
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function backupProps(ScheduledDatabaseBackup $backup, array $parameters): array
    {
        $latestLog = $backup->latest_log;
        $status = $latestLog?->status;

        $timingText = null;
        $sizeText = null;
        if ($latestLog) {
            if ($status === 'running') {
                $timingText = 'Running for '.calculateDuration($latestLog->created_at, now());
            } else {
                $timingText = Carbon::parse($latestLog->finished_at)->diffForHumans()
                    .' ('.calculateDuration($latestLog->created_at, $latestLog->finished_at).')'
                    .' • '.Carbon::parse($latestLog->finished_at)->format('M j, H:i');
            }
            if ($status === 'success' && $latestLog->size > 0) {
                $sizeText = formatBytes($latestLog->size);
            }
        }

        return [
            'id' => $backup->id,
            'frequency' => $backup->frequency,
            'saveS3' => (bool) $backup->save_s3,
            'status' => $status,
            'timingText' => $timingText,
            'sizeText' => $sizeText,
            'executeUrl' => route('project.database.backup.execution', [...$parameters, 'backup_uuid' => $backup->uuid]),
        ];
    }
}
