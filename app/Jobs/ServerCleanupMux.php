<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ServerCleanupMux implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function backoff(): int
    {
        return isDev() ? 1 : 3;
    }

    public function __construct(public Server $server) {}

    public function handle(): mixed
    {
        try {
            if ($this->server->serverStatus() === false) {
                return 'Server is not reachable or not ready.';
            }
            SshMultiplexingHelper::removeMuxFile($this->server);

            return null;
        } catch (\Throwable $e) {
            return handleError($e);
        }
    }
}
