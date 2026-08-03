<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Events\DatabaseProxyStopped;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDatabaseInstance;
use Lorisleiva\Actions\Concerns\AsAction;

class StopDatabaseProxy
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(StandaloneDatabaseInstance|ServiceDatabase $database): void
    {
        $server = data_get($database, 'destination.server');
        $uuid = (string) data_get($database, 'uuid');
        if ($database->getMorphClass() === ServiceDatabase::class) {
            $server = data_get($database, 'service.server');
        }
        // The destination's (or service's) server may have been soft-deleted already (e.g.
        // mid-flight during a "delete server + delete all resources" request) - the default
        // belongsTo query excludes trashed rows, so $server resolves to null rather than a
        // Server instance. There's no real container left to stop in that case anyway.
        if (! $server instanceof Server) {
            return;
        }
        instant_remote_process(["docker rm -f {$uuid}-proxy"], $server);

        $database->save();

        DatabaseProxyStopped::dispatch();

    }
}
