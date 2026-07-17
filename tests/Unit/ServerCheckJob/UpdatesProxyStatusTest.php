<?php

declare(strict_types=1);

namespace Tests\Unit\ServerCheckJob;

use App\Actions\Docker\GetContainersStatus;
use App\Jobs\ConnectProxyToNetworksJob;
use App\Jobs\ServerCheckJob;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class UpdatesProxyStatusTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_updates_the_proxy_status_without_crashing_when_the_proxy_container_is_found(): void
    {
        // Regression test: handle() called $proxy->setAttribute(...)/$proxy->save() on
        // $server->proxy, which is a Spatie SchemalessAttributes wrapper, not an Eloquent
        // model - neither method exists on it, and both calls silently proxy through
        // __call() to the wrapper's internal plain Collection, which doesn't have
        // setAttribute() either, fatally erroring every time a found proxy container's
        // status needed to be recorded. The fix uses the wrapper's own set() API plus an
        // explicit $server->save(), matching every other call site in the codebase.
        $server = $this->getMockBuilder(Server::class)
            ->onlyMethods([
                'serverStatus',
                'isSwarmWorker',
                'isBuildServer',
                'getContainers',
                'isSentinelEnabled',
                'isLogDrainEnabled',
                'proxySet',
                'isSwarm',
            ])
            ->getMock();

        $server->method('serverStatus')->willReturn(true);
        $server->method('isSwarmWorker')->willReturn(false);
        $server->method('isBuildServer')->willReturn(false);
        $server->method('isSentinelEnabled')->willReturn(false);
        $server->method('isLogDrainEnabled')->willReturn(false);
        $server->method('proxySet')->willReturn(true);
        $server->method('isSwarm')->willReturn(false);
        $server->method('getContainers')->willReturn([
            'containers' => collect([
                (object) [
                    'Name' => '/coolify-proxy',
                    'State' => (object) ['Status' => 'running'],
                ],
            ]),
            'containerReplicates' => null,
        ]);
        $server->setTable('servers');
        $server->id = 999;
        $server->ip = '127.0.0.1';
        $server->exists = true;
        $server->syncOriginal();

        GetContainersStatus::shouldRun()->once()->andReturnNull();
        Bus::fake([ConnectProxyToNetworksJob::class]);

        (new ServerCheckJob($server))->handle();

        expect($server->proxy->get('status'))->toBe('running');
    }
}
