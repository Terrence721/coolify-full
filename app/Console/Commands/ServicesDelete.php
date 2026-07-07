<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Support\DatabaseEngineRegistry;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

class ServicesDelete extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'services:delete';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete a service from the database';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $resource = select(
            'What service do you want to delete?',
            ['Application', 'Database', 'Service', 'Server'],
        );
        if ($resource === 'Application') {
            $this->deleteApplication();
        } elseif ($resource === 'Database') {
            $this->deleteDatabase();
        } elseif ($resource === 'Service') {
            $this->deleteService();
        } elseif ($resource === 'Server') {
            $this->deleteServer();
        }
    }

    private function deleteServer(): void
    {
        $servers = Server::all();
        if ($servers->count() === 0) {
            $this->error('There are no applications to delete.');

            return;
        }
        $serversToDelete = multiselect(
            label: 'What server do you want to delete?',
            options: $servers->pluck('name', 'id')->sortKeys(),
        );

        foreach ($serversToDelete as $server) {
            $toDelete = $servers->where('id', $server)->first();
            if ($toDelete) {
                $this->info("Deleting: {$toDelete->name}");
                $confirmed = confirm('Are you sure you want to delete all selected resources?');
                if (! $confirmed) {
                    break;
                }
                $toDelete->delete();
            }
        }
    }

    private function deleteApplication(): void
    {
        $applications = Application::all();
        if ($applications->count() === 0) {
            $this->error('There are no applications to delete.');

            return;
        }
        $applicationsToDelete = multiselect(
            'What application do you want to delete?',
            $applications->pluck('name', 'id')->sortKeys(),
        );

        foreach ($applicationsToDelete as $application) {
            $toDelete = $applications->where('id', $application)->first();
            if ($toDelete) {
                $this->info("Deleting: {$toDelete->name}");
                $confirmed = confirm('Are you sure you want to delete all selected resources? ');
                if (! $confirmed) {
                    break;
                }
                DeleteResourceJob::dispatch($toDelete);
            }
        }
    }

    private function deleteDatabase(): void
    {
        // Collect all databases from all engines (see DatabaseEngineRegistry) with unique identifiers
        $allDatabases = collect();
        $databaseOptions = collect();

        foreach (DatabaseEngineRegistry::all() as $engine) {
            $modelClass = $engine->modelClass;
            foreach ($modelClass::all() as $db) {
                $key = "{$engine->type}_{$db->id}";
                $allDatabases->put($key, $db);
                $databaseOptions->put($key, "{$db->name} ({$engine->displayName})");
            }
        }

        if ($allDatabases->count() === 0) {
            $this->error('There are no databases to delete.');

            return;
        }

        $databasesToDelete = multiselect(
            'What database do you want to delete?',
            $databaseOptions->sortKeys(),
        );

        foreach ($databasesToDelete as $databaseKey) {
            $toDelete = $allDatabases->get($databaseKey);
            if ($toDelete) {
                $this->info("Deleting: {$toDelete->name}");
                $confirmed = confirm('Are you sure you want to delete all selected resources?');
                if (! $confirmed) {
                    return;
                }
                DeleteResourceJob::dispatch($toDelete);
            }
        }
    }

    private function deleteService(): void
    {
        $services = Service::all();
        if ($services->count() === 0) {
            $this->error('There are no services to delete.');

            return;
        }
        $servicesToDelete = multiselect(
            'What service do you want to delete?',
            $services->pluck('name', 'id')->sortKeys(),
        );

        foreach ($servicesToDelete as $service) {
            $toDelete = $services->where('id', $service)->first();
            if ($toDelete) {
                $this->info("Deleting: {$toDelete->name}");
                $confirmed = confirm('Are you sure you want to delete all selected resources?');
                if (! $confirmed) {
                    return;
                }
                DeleteResourceJob::dispatch($toDelete);
            }
        }
    }
}
