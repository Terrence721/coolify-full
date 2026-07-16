<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Docker;

use App\Actions\Database\StopDatabaseProxy;
use App\Actions\Docker\GetContainersStatus;
use App\Events\ServiceChecked;
use App\Models\Server;
use App\Models\StandaloneMysql;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GetContainersStatusTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_stops_a_public_database_proxy_when_the_database_is_not_running(): void
    {
        $server = $this->getMockBuilder(Server::class)
            ->onlyMethods(['isFunctional', 'isSwarm', 'applications', 'services', 'databases', 'previews', 'getContainers'])
            ->getMock();

        $server->method('isFunctional')->willReturn(true);
        $server->method('isSwarm')->willReturn(false);
        $server->method('applications')->willReturn(collect());
        $services = $this->getMockBuilder(HasMany::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $services->method('get')->willReturn(collect());
        $server->method('services')->willReturn($services);
        $server->setRelation('team', (object) ['id' => 1]);

        $database = StandaloneMysql::factory()->create([
            'uuid' => 'redis-test-1',
            'name' => 'redis-test-1',
            'status' => 'running',
            'is_public' => true,
        ]);

        $database->setRelation('destination', (object) ['server' => $server]);
        $database->setRelation('environment', (object) ['project' => (object) ['team' => (object) ['id' => 1]]]);

        $server->method('databases')->willReturn(collect([$database]));
        $server->method('previews')->willReturn(collect());
        $server->method('getContainers')->willReturn([
            'containers' => collect([
                (object) [
                    'Name' => '/unrelated-container',
                    'Config' => (object) [
                        'Labels' => [],
                    ],
                    'State' => (object) [
                        'Status' => 'running',
                        'Health' => (object) ['Status' => 'healthy'],
                    ],
                ],
            ]),
            'containerReplicates' => null,
        ]);

        Event::fake([ServiceChecked::class]);

        StopDatabaseProxy::shouldRun()
            ->once()
            ->with($database)
            ->andReturnNull();

        GetContainersStatus::run($server);

        $this->assertStringStartsWith('exited', $database->fresh()->status);
    }
}
