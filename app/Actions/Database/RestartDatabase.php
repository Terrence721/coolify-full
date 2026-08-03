<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Models\Server;
use App\Models\StandaloneDatabaseInstance;
use Lorisleiva\Actions\Concerns\AsAction;

class RestartDatabase
{
    use AsAction;

    public function handle(StandaloneDatabaseInstance $database): mixed
    {
        // The destination's server may have been soft-deleted already (e.g. mid-flight during a
        // "delete server + delete all resources" request) - the default belongsTo query excludes
        // trashed rows, so destination.server resolves to null rather than a Server instance.
        $server = data_get($database, 'destination.server');
        if (! $server instanceof Server || ! $server->isFunctional()) {
            return 'Server is not functional';
        }
        StopDatabase::run($database, dockerCleanup: false);

        return StartDatabase::run($database);
    }
}
