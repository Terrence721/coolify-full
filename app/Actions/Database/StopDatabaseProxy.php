<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Models\StandaloneDatabaseInstance;
use App\Events\DatabaseProxyStopped;
use App\Models\ServiceDatabase;
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
        instant_remote_process(["docker rm -f {$uuid}-proxy"], $server);

        $database->save();

        DatabaseProxyStopped::dispatch();

    }
}
