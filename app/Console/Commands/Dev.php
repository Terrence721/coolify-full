<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CheckHelperImageJob;
use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTaskExecution;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * First-boot/dev-startup initialization. The dev container's s6 `init-setup` service runs
 * `php artisan dev --init` after `composer install` + `migrate` on every container start —
 * this command is what makes a fresh clone come up working (APP_KEY, storage symlink,
 * seeded database) and what un-sticks interrupted jobs on subsequent starts.
 *
 * Restored from upstream after being lost in the history-clearing commit (509a342f): the s6
 * script kept calling it, but execline's `foreground` silently swallowed the "command not
 * found" failure, so fresh clones booted with no APP_KEY and an empty, unseeded database.
 */
class Dev extends Command
{
    protected $signature = 'dev {--init}';

    protected $description = 'Helper commands for development.';

    public function handle(): void
    {
        if ($this->option('init')) {
            $this->init();

            return;
        }
    }

    public function init(): void
    {
        if (empty(config('app.key'))) {
            echo "   INFO  Generating APP_KEY.\n";
            Artisan::call('key:generate');
        }

        if (! file_exists(public_path('storage'))) {
            echo "   INFO  Generating storage link.\n";
            Artisan::call('storage:link');
        }

        // Seed database if it's empty
        $settings = InstanceSettings::find(0);
        if (! $settings) {
            echo "   INFO  Initializing instance, seeding database.\n";
            Artisan::call('migrate --seed');
        } else {
            echo "   INFO  Instance already initialized.\n";
        }

        // Clean up stuck jobs and stale locks on development startup
        try {
            echo "   INFO  Cleaning up Redis (stuck jobs and stale locks)...\n";
            Artisan::call('cleanup:redis', ['--restart' => true, '--clear-locks' => true]);
            echo "   INFO  Redis cleanup completed.\n";
        } catch (\Throwable $e) {
            echo "   ERROR  Redis cleanup failed: {$e->getMessage()}\n";
        }

        try {
            $updatedTaskCount = ScheduledTaskExecution::where('status', 'running')->update([
                'status' => 'failed',
                'message' => 'Marked as failed during Coolify startup - job was interrupted',
                'finished_at' => Carbon::now(),
            ]);

            if ($updatedTaskCount > 0) {
                echo "   INFO  Marked {$updatedTaskCount} stuck scheduled task executions as failed.\n";
            }
        } catch (\Throwable $e) {
            echo "   ERROR  Could not clean up stuck scheduled task executions: {$e->getMessage()}\n";
        }

        try {
            $updatedBackupCount = ScheduledDatabaseBackupExecution::where('status', 'running')->update([
                'status' => 'failed',
                'message' => 'Marked as failed during Coolify startup - job was interrupted',
                'finished_at' => Carbon::now(),
            ]);

            if ($updatedBackupCount > 0) {
                echo "   INFO  Marked {$updatedBackupCount} stuck database backup executions as failed.\n";
            }
        } catch (\Throwable $e) {
            echo "   ERROR  Could not clean up stuck database backup executions: {$e->getMessage()}\n";
        }

        CheckHelperImageJob::dispatch();
    }
}
