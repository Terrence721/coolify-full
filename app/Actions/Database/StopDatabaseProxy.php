<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Contracts\StandaloneDatabaseInstance;
use App\Events\DatabaseProxyStopped;
use App\Models\ServiceDatabase;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

class StopDatabaseProxy
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle((Model&StandaloneDatabaseInstance)|ServiceDatabase $database): void
    {
        $server = data_get($database, 'destination.server');
        $uuid = $database->uuid;
        if ($database->getMorphClass() === ServiceDatabase::class) {
            $server = data_get($database, 'service.server');
        }
        instant_remote_process(["docker rm -f {$uuid}-proxy"], $server);

        $database->save();

        DatabaseProxyStopped::dispatch();

    }
}
