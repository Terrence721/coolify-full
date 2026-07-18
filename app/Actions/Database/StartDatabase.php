<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Models\StandaloneDatabaseInstance;
use App\Models\Server;
use App\Support\DatabaseEngineRegistry;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;

class StartDatabase
{
    use AsAction;

    public function configureJob(JobDecorator $job): void
    {
        $job->onQueue(deployment_queue());
    }

    public function handle(StandaloneDatabaseInstance $database): mixed
    {
        $server = data_get($database, 'destination.server');
        if (! $server instanceof Server || ! $server->isFunctional()) {
            return 'Server is not functional';
        }

        $engine = DatabaseEngineRegistry::forInstance($database);
        if (! $engine) {
            throw new \RuntimeException('Unsupported database type.');
        }

        $startActionClass = $engine->startActionClass;
        $activity = $startActionClass::run($database);

        $isPublic = (bool) data_get($database, 'is_public', false);
        $publicPort = data_get($database, 'public_port');
        if ($isPublic && $publicPort) {
            StartDatabaseProxy::dispatch($database);
        }

        return $activity;
    }
}
