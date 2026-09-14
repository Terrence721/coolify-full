<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CleanupHelperContainersJob;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\SslCertificate;
use App\Models\Team;
use App\Support\DatabaseEngineRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupStuckedResources extends Command
{
    protected $signature = 'cleanup:stucked-resources';

    protected $description = 'Cleanup Stucked Resources';

    public function handle(): void
    {
        $this->cleanup_stucked_resources();
    }

    private function cleanup_stucked_resources(): void
    {
        try {
            $teams = Team::all()->filter(function ($team) {
                return $team->members()->count() === 0 && $team->servers()->count() === 0;
            });
            foreach ($teams as $team) {
                $team->delete();
            }
            $servers = Server::all()->filter(function ($server) {
                return $server->isFunctional();
            });
            foreach ($servers as $server) {
                CleanupHelperContainersJob::dispatch($server);
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in cleanup_stucked_resources().', ['error' => $e->getMessage()]);

            echo "Error in cleaning stucked resources: {$e->getMessage()}\n";
        }
        try {
            $servers = Server::onlyTrashed()->get();
            foreach ($servers as $server) {
                echo "Force deleting stuck server: {$server->name}\n";
                $server->forceDelete();
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in cleanup_stucked_resources().', ['error' => $e->getMessage()]);

            echo "Error in cleaning stuck servers: {$e->getMessage()}\n";
        }
        try {
            $applicationsDeploymentQueue = ApplicationDeploymentQueue::get();
            foreach ($applicationsDeploymentQueue as $applicationDeploymentQueue) {
                if (is_null($applicationDeploymentQueue->application)) {
                    echo "Deleting stuck application deployment queue: {$applicationDeploymentQueue->id}\n";
                    $applicationDeploymentQueue->delete();
                }
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in cleanup_stucked_resources().', ['error' => $e->getMessage()]);

            echo "Error in cleaning stuck application deployment queue: {$e->getMessage()}\n";
        }
        try {
            $applications = Application::withTrashed()->whereNotNull('deleted_at')->get();
            foreach ($applications as $application) {
                echo "Deleting stuck application: {$application->name}\n";
                DeleteResourceJob::dispatch($application);
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in cleanup_stucked_resources().', ['error' => $e->getMessage()]);

            echo "Error in cleaning stuck application: {$e->getMessage()}\n";
        }
        try {
            $applicationsPreviews = ApplicationPreview::get();
            foreach ($applicationsPreviews as $applicationPreview) {
                if (! data_get($applicationPreview, 'application')) {
                    echo "Deleting stuck application preview: {$applicationPreview->uuid}\n";
                    DeleteResourceJob::dispatch($applicationPreview);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in cleanup_stucked_resources().', ['error' => $e->getMessage()]);

            echo "Error in cleaning stuck application: {$e->getMessage()}\n";
        }
        try {
            $applicationsPreviews = ApplicationPreview::withTrashed()->whereNotNull('deleted_at')->get();
            foreach ($applicationsPreviews as $applicationPreview) {
                echo "Deleting stuck application preview: {$applicationPreview->fqdn}\n";
                DeleteResourceJob::dispatch($applicationPreview);
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in cleanup_stucked_resources().', ['error' => $e->getMessage()]);

            echo "Error in cleaning stuck application: {$e->getMessage()}\n";
        }
        foreach (DatabaseEngineRegistry::all() as $engine) {
            try {
                $modelClass = $engine->modelClass;
                $stuckInstances = $modelClass::withTrashed()->whereNotNull('deleted_at')->get();
                foreach ($stuckInstances as $instance) {
                    echo "Deleting stuck {$engine->type}: {$instance->name}\n";
                    DeleteResourceJob::dispatch($instance);
                }
            } catch (\Throwable $e) {
                Log::error('Unhandled exception in cleanup_stucked_resources().', ['error' => $e->getMessage()]);

                echo "Error in cleaning stuck {$engine->type}: {$e->getMessage()}\n";
            }
        }
        try {
            $services = Service::withTrashed()->whereNotNull('deleted_at')->get();
            foreach ($services as $service) {
                echo "Deleting stuck service: {$service->name}\n";
                DeleteResourceJob::dispatch($service);
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in cleanup_stucked_resources().', ['error' => $e->getMessage()]);

            echo "Error in cleaning stuck service: {$e->getMessage()}\n";
        }
        try {
            $serviceApps = ServiceApplication::withTrashed()->whereNotNull('deleted_at')->get();
            foreach ($serviceApps as $serviceApp) {
                echo "Deleting stuck serviceapp: {$serviceApp->name}\n";
                $serviceApp->forceDelete();
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in cleanup_stucked_resources().', ['error' => $e->getMessage()]);

            echo "Error in cleaning stuck serviceapp: {$e->getMessage()}\n";
        }
        try {
            $serviceDbs = ServiceDatabase::withTrashed()->whereNotNull('deleted_at')->get();
            foreach ($serviceDbs as $serviceDb) {
                echo "Deleting stuck serviceapp: {$serviceDb->name}\n";
                $serviceDb->forceDelete();
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in cleanup_stucked_resources().', ['error' => $e->getMessage()]);

            echo "Error in cleaning stuck serviceapp: {$e->getMessage()}\n";
        }
        try {
            $scheduled_tasks = ScheduledTask::all();
            foreach ($scheduled_tasks as $scheduled_task) {
                if (! $scheduled_task->service && ! $scheduled_task->application) {
                    echo "Deleting stuck scheduledtask: {$scheduled_task->name}\n";
                    $scheduled_task->delete();
                }
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in cleanup_stucked_resources().', ['error' => $e->getMessage()]);

            echo "Error in cleaning stuck scheduledtasks: {$e->getMessage()}\n";
        }

        try {
            $scheduled_backups = ScheduledDatabaseBackup::all();
            foreach ($scheduled_backups as $scheduled_backup) {
                try {
                    $server = $scheduled_backup->server();
                    if (! $server) {
                        echo "Deleting stuck scheduledbackup: {$scheduled_backup->uuid}\n";
                        $scheduled_backup->delete();
                    }
                } catch (\Throwable $e) {
                    Log::error('Unhandled exception in cleanup_stucked_resources().', ['error' => $e->getMessage()]);

                    echo "Error checking server for scheduledbackup {$scheduled_backup->id}: {$e->getMessage()}\n";
                }
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in cleanup_stucked_resources().', ['error' => $e->getMessage()]);

            echo "Error in cleaning stuck scheduledbackups: {$e->getMessage()}\n";
        }

        // Cleanup any resources that are not attached to any environment or destination or server
        try {
            $applications = Application::all();
            foreach ($applications as $application) {
                if (! data_get($application, 'environment')) {
                    echo 'Application without environment: '.$application->name.'\n';
                    DeleteResourceJob::dispatch($application);

                    continue;
                }
                if (! $application->destination) {
                    echo 'Application without destination: '.$application->name.'\n';
                    DeleteResourceJob::dispatch($application);

                    continue;
                }
                if (! data_get($application, 'destination.server')) {
                    echo 'Application without server: '.$application->name.'\n';
                    DeleteResourceJob::dispatch($application);

                    continue;
                }
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in cleanup_stucked_resources().', ['error' => $e->getMessage()]);

            echo "Error in application: {$e->getMessage()}\n";
        }
        // One loop over every registered engine, not one hand-written block per engine - the
        // old per-engine blocks (postgresql/redis/mongodb/mysql/mariadb only) silently never
        // checked dragonfly/keydb/clickhouse at all, so orphaned resources of those 3 engines
        // were permanently invisible to this cleanup command. where('id', '!=', 0) was already
        // applied to postgresql only; broadened to every engine here since it's a strictly safer
        // superset (excluding a sentinel id=0 row, if one exists, from deletion) and there's no
        // reason the other 7 engines should be less protected than postgresql was.
        foreach (DatabaseEngineRegistry::all() as $engine) {
            try {
                $modelClass = $engine->modelClass;
                $instances = $modelClass::where('id', '!=', 0)->get();
                foreach ($instances as $instance) {
                    if (! data_get($instance, 'environment')) {
                        echo "{$engine->displayName} without environment: {$instance->name}\n";
                        DeleteResourceJob::dispatch($instance);

                        continue;
                    }
                    if (! data_get($instance, 'destination')) {
                        echo "{$engine->displayName} without destination: {$instance->name}\n";
                        DeleteResourceJob::dispatch($instance);

                        continue;
                    }
                    if (! data_get($instance, 'destination.server')) {
                        echo "{$engine->displayName} without server: {$instance->name}\n";
                        DeleteResourceJob::dispatch($instance);

                        continue;
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Unhandled exception in cleanup_stucked_resources().', ['error' => $e->getMessage()]);

                echo "Error in {$engine->type}: {$e->getMessage()}\n";
            }
        }

        try {
            $services = Service::all();
            foreach ($services as $service) {
                if (! data_get($service, 'environment')) {
                    echo 'Service without environment: '.$service->name.'\n';
                    DeleteResourceJob::dispatch($service);

                    continue;
                }
                if (! $service->destination) {
                    echo 'Service without destination: '.$service->name.'\n';
                    DeleteResourceJob::dispatch($service);

                    continue;
                }
                if (! data_get($service, 'server')) {
                    echo 'Service without server: '.$service->name.'\n';
                    DeleteResourceJob::dispatch($service);

                    continue;
                }
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in cleanup_stucked_resources().', ['error' => $e->getMessage()]);

            echo "Error in service: {$e->getMessage()}\n";
        }
        try {
            $serviceApplications = ServiceApplication::all();
            foreach ($serviceApplications as $service) {
                if (! data_get($service, 'service')) {
                    echo 'ServiceApplication without service: '.$service->name.'\n';
                    $service->forceDelete();

                    continue;
                }
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in cleanup_stucked_resources().', ['error' => $e->getMessage()]);

            echo "Error in serviceApplications: {$e->getMessage()}\n";
        }
        try {
            $serviceDatabases = ServiceDatabase::all();
            foreach ($serviceDatabases as $service) {
                if (! data_get($service, 'service')) {
                    echo 'ServiceDatabase without service: '.$service->name.'\n';
                    $service->forceDelete();

                    continue;
                }
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in cleanup_stucked_resources().', ['error' => $e->getMessage()]);

            echo "Error in ServiceDatabases: {$e->getMessage()}\n";
        }

        try {
            $orphanedCerts = SslCertificate::whereNotIn('server_id', function ($query) {
                $query->select('id')->from('servers');
            })->get();

            foreach ($orphanedCerts as $cert) {
                echo "Deleting orphaned SSL certificate: {$cert->id} (server_id: {$cert->server_id})\n";
                $cert->delete();
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in cleanup_stucked_resources().', ['error' => $e->getMessage()]);

            echo "Error in cleaning orphaned SSL certificates: {$e->getMessage()}\n";
        }
    }
}
