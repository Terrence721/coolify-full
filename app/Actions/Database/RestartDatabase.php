<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Contracts\StandaloneDatabaseInstance;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

class RestartDatabase
{
    use AsAction;

    public function handle(Model&StandaloneDatabaseInstance $database): mixed
    {
        $server = data_get($database, 'destination.server');
        if (! $server->isFunctional()) {
            return 'Server is not functional';
        }
        StopDatabase::run($database, dockerCleanup: false);

        return StartDatabase::run($database);
    }
}
