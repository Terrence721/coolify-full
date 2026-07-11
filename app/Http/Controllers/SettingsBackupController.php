<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesScheduledDatabaseBackups;
use App\Jobs\DatabaseBackupJob;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class SettingsBackupController extends Controller
{
    use ManagesScheduledDatabaseBackups;

    public function index(Request $request): Response|RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $server = Server::findOrFail(0);
        $database = StandalonePostgresql::whereName('coolify-db')->first();
        $serverFunctional = $server->isFunctional();

        // Mirrors the original Livewire mount(): a backup schedule is disabled as soon as
        // the server is seen as non-functional, regardless of which state the page renders.
        $backup = $database?->scheduledBackups()->first();
        if ($backup && ! $serverFunctional) {
            $backup->enabled = false;
            $backup->save();
        }

        if (! $serverFunctional) {
            return Inertia::render('SettingsBackup', [
                'server' => ['uuid' => $server->uuid],
                'serverFunctional' => false,
                'database' => null,
            ]);
        }

        if (! $database) {
            return Inertia::render('SettingsBackup', [
                'server' => ['uuid' => $server->uuid],
                'serverFunctional' => true,
                'database' => null,
                'urls' => [
                    'addDatabase' => route('settings.backup.add-database'),
                ],
            ]);
        }

        if ($database->status !== 'running') {
            $database->status = 'running';
            $database->save();
        }

        $skip = max(0, (int) $request->query('skip', 0));
        $defaultTake = 10;
        $executions = collect();
        $executionsCount = 0;
        if ($backup) {
            ['executions' => $executions, 'count' => $executionsCount] = $backup->executionsPaginated($skip, $defaultTake);
        }

        return Inertia::render('SettingsBackup', [
            'server' => ['uuid' => $server->uuid],
            'serverFunctional' => true,
            'database' => [
                'uuid' => $database->uuid,
                'name' => $database->name,
                'description' => $database->description,
                'postgresUser' => $database->postgres_user,
                'postgresPassword' => $database->postgres_password,
            ],
            'backup' => $backup ? $this->backupEditProps($backup) : null,
            's3Storages' => $this->s3StorageOptions(0),
            'executions' => $backup ? $executions->map(fn (ScheduledDatabaseBackupExecution $execution) => $this->executionProps(
                $execution,
                route('settings.backup.execution.destroy', ['execution_id' => $execution->id]),
                route('download.backup', ['executionId' => $execution->id]),
            )) : [],
            'executionsCount' => $executionsCount,
            'skip' => $skip,
            'defaultTake' => $defaultTake,
            'currentPage' => intdiv($skip, $defaultTake) + 1,
            'showNext' => $executions->count() > 0 && $executions->count() >= $defaultTake,
            'showPrev' => $skip > 0,
            'identityUpdateUrl' => route('settings.backup.update'),
            'urls' => [
                'update' => route('settings.backup.schedule.update'),
                'backupNow' => route('settings.backup.backup-now'),
                'cleanupFailed' => route('settings.backup.cleanup-failed'),
                'cleanupDeleted' => route('settings.backup.cleanup-deleted'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $database = StandalonePostgresql::whereName('coolify-db')->firstOrFail();

        $validated = Validator::make($request->all(), [
            'name' => 'required|string',
            'description' => 'nullable|string',
            'postgres_user' => 'required|string',
            'postgres_password' => 'required|string',
        ])->validate();

        $database->update($validated);

        return back()->with('success', 'Backup updated.');
    }

    public function addDatabase(): RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        try {
            $server = Server::findOrFail(0);
            $out = instant_remote_process(['docker inspect coolify-db'], $server);
            $envs = format_docker_envs_to_json($out);

            $database = new StandalonePostgresql;
            $database->forceFill([
                'id' => 0,
                'name' => 'coolify-db',
                'description' => 'Coolify database',
                'postgres_user' => $envs['POSTGRES_USER'],
                'postgres_password' => $envs['POSTGRES_PASSWORD'],
                'postgres_db' => $envs['POSTGRES_DB'],
                'status' => 'running',
                'destination_type' => StandaloneDocker::class,
                'destination_id' => 0,
            ]);
            $database->save();

            ScheduledDatabaseBackup::create([
                'id' => 0,
                'enabled' => true,
                'save_s3' => false,
                'frequency' => '0 0 * * *',
                'database_id' => $database->id,
                'database_type' => StandalonePostgresql::class,
                'team_id' => currentTeam()->id,
            ]);

            return back()->with('success', 'Coolify database added for backups.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to add Coolify database: '.$e->getMessage());
        }
    }

    public function updateSchedule(Request $request): RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $backup = $this->resolveBackup();
        if (! $backup instanceof ScheduledDatabaseBackup) {
            return $backup;
        }

        $error = $this->applyBackupScheduleUpdate($request, $backup, 0);
        if ($error) {
            return back()->with('error', $error);
        }

        return back()->with('success', 'Backup updated successfully.');
    }

    public function backupNow(): RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $backup = $this->resolveBackup();
        if (! $backup instanceof ScheduledDatabaseBackup) {
            return $backup;
        }

        DatabaseBackupJob::dispatch($backup);

        return back()->with('success', 'Backup queued. It will be available in a few minutes.');
    }

    public function cleanupFailedExecutions(): RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $backup = $this->resolveBackup();
        if (! $backup instanceof ScheduledDatabaseBackup) {
            return $backup;
        }

        $this->cleanupFailedBackupExecutions($backup);

        return back()->with('success', 'Failed backups cleaned up.');
    }

    public function cleanupDeletedExecutions(): RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $backup = $this->resolveBackup();
        if (! $backup instanceof ScheduledDatabaseBackup) {
            return $backup;
        }

        $deletedCount = $this->cleanupDeletedBackupExecutions($backup);
        if ($deletedCount === 0) {
            return back()->with('info', 'No backup entries found that are deleted from local storage.');
        }

        return back()->with('success', "Cleaned up {$deletedCount} backup entries deleted from local storage.");
    }

    public function destroyExecution(Request $request, int $execution_id): RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $backup = $this->resolveBackup();
        if (! $backup instanceof ScheduledDatabaseBackup) {
            return $backup;
        }

        if (! verifyPasswordConfirmation($request->input('password'))) {
            return back()->with('error', 'The provided password is incorrect.');
        }

        $database = StandalonePostgresql::whereName('coolify-db')->first();
        $error = $this->deleteBackupExecution($request, $backup, $execution_id, $database?->destination?->server);
        if ($error) {
            return back()->with('error', $error);
        }

        return back()->with('success', 'Backup deleted.');
    }

    private function resolveBackup(): ScheduledDatabaseBackup|RedirectResponse
    {
        $database = StandalonePostgresql::whereName('coolify-db')->first();
        $backup = $database?->scheduledBackups()->first();
        if (! $backup) {
            return redirect()->route('settings.backup');
        }

        return $backup;
    }
}
