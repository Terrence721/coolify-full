<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Database;

use App\Actions\Database\StopDatabaseProxy;
use App\Events\DatabaseProxyStopped;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Models\StandaloneMysql;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Fakes\RemoteProcessFake;
use Tests\TestCase;

require_once __DIR__.'/../../Support/Fakes/database_action_overrides.php';

final class StopDatabaseProxyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RemoteProcessFake::reset();
        Event::fake();
    }

    private function fakeStandaloneDatabase(): StandaloneMysql
    {
        $db = new StandaloneMysql([
            'uuid' => 'db-123',
        ]);
        $db->id = 999;
        $db->exists = true;

        $destination = new class($this->createStub(Server::class))
        {
            public function __construct(public Server $server) {}
        };
        $db->setRelation('destination', $destination);

        return $db;
    }

    #[Test]
    public function it_stops_the_proxy_container_for_a_standalone_database()
    {
        $db = $this->fakeStandaloneDatabase();

        (new StopDatabaseProxy)->handle($db);

        $calls = RemoteProcessFake::$instantRemoteProcessCalls;
        $this->assertCount(1, $calls);
        $this->assertSame(['docker rm -f db-123-proxy'], $calls[0][0]);
        Event::assertDispatched(DatabaseProxyStopped::class);
    }

    #[Test]
    public function it_stops_the_proxy_container_for_a_service_database_via_the_service_server_relation()
    {
        $serviceDb = new ServiceDatabase(['name' => 'mydb']);
        $serviceDb->uuid = 'svc-db-456';
        // save()'s INSERT would otherwise fail the NOT NULL service_id constraint - marking it
        // as existing makes save() run an UPDATE instead, which affects zero rows silently.
        $serviceDb->id = 998;
        $serviceDb->exists = true;

        $service = new class($this->createStub(Server::class))
        {
            public function __construct(public Server $server) {}
        };
        $serviceDb->setRelation('service', $service);

        (new StopDatabaseProxy)->handle($serviceDb);

        $calls = RemoteProcessFake::$instantRemoteProcessCalls;
        $this->assertCount(1, $calls);
        $this->assertSame(['docker rm -f svc-db-456-proxy'], $calls[0][0]);
    }

    /**
     * Regression test for a real bug: $server was read from destination.server (or
     * service.server) with no null check, then passed straight into
     * instant_remote_process(), whose real signature requires a non-nullable Server -
     * destination.server resolves to null once the destination's server has been
     * soft-deleted (the default belongsTo excludes trashed rows), which crashed with an
     * uncaught TypeError. Same root shape as the StopApplication/StopDatabase/RestartDatabase
     * null-server crashes already fixed this session, just in two sibling files (this one and
     * StartDatabaseProxy.php) that pattern never reached - inherited verbatim from the
     * original upstream import.
     */
    #[Test]
    public function it_returns_early_instead_of_crashing_when_the_server_is_null()
    {
        $db = $this->fakeStandaloneDatabase();
        $db->setRelation('destination', (object) ['server' => null]);

        (new StopDatabaseProxy)->handle($db);

        $this->assertEmpty(RemoteProcessFake::$instantRemoteProcessCalls);
        Event::assertNotDispatched(DatabaseProxyStopped::class);
    }
}
