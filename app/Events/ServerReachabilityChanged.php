<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Server;
use Illuminate\Foundation\Events\Dispatchable;

class ServerReachabilityChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Server $server
    ) {
        $this->server->isReachableChanged();
    }
}
