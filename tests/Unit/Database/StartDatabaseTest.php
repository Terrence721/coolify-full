<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Database;

use App\Actions\Database\StartClickhouse;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StartDragonfly;
use App\Actions\Database\StartKeydb;
use App\Actions\Database\StartMariadb;
use App\Actions\Database\StartMongodb;
use App\Actions\Database\StartMysql;
use App\Actions\Database\StartPostgresql;
use App\Actions\Database\StartRedis;
use App\Models\Server;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Lorisleiva\Actions\Decorators\JobDecorator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class StartDatabaseTest extends TestCase
{
    /**
     * @param  class-string  $class
     */
    private function fakeDatabase(string $class, bool $functional = true, ?string $morphClass = null): mixed
    {
        $server = $this->createStub(Server::class);
        $server->method('isFunctional')->willReturn($functional);

        $destination = new class($server)
        {
            public string $network;

            public function __construct(public $server)
            {
                $this->network = 'net-db';
            }
        };

        // Only getMorphClass() is mocked — everything else (setRelation(), attribute
        // assignment) needs to stay real, since PHPUnit's createMock() stubs out every
        // public method by default, which would silently break setRelation(). Configure
        // it once here: a second ->method('getMorphClass') call on an existing mock does
        // not override this configuration.
        /** @var Model&MockObject $db */
        $db = $this->getMockBuilder($class)
            ->onlyMethods(['getMorphClass'])
            ->getMock();
        $db->method('getMorphClass')->willReturn($morphClass ?? $class);

        $db->setRelation('destination', $destination);
        $db->is_public = false;
        $db->public_port = null;

        return $db;
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function it_returns_error_when_server_not_functional()
    {
        $db = $this->fakeDatabase(StandaloneRedis::class, functional: false);

        $action = new StartDatabase;
        $result = $action->handle($db);

        $this->assertSame('Server is not functional', $result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function it_calls_start_postgresql()
    {
        $db = $this->fakeDatabase(StandalonePostgresql::class);

        StartPostgresql::shouldRun()
            ->once()
            ->with($db)
            ->andReturn('pg-started');

        $action = new StartDatabase;
        $result = $action->handle($db);

        $this->assertSame('pg-started', $result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function it_calls_start_redis()
    {
        $db = $this->fakeDatabase(StandaloneRedis::class);

        StartRedis::shouldRun()
            ->once()
            ->with($db)
            ->andReturn('redis-started');

        $action = new StartDatabase;
        $result = $action->handle($db);

        $this->assertSame('redis-started', $result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function it_calls_start_mongodb()
    {
        $db = $this->fakeDatabase(StandaloneMongodb::class);

        StartMongodb::shouldRun()
            ->once()
            ->with($db)
            ->andReturn('mongo-started');

        $action = new StartDatabase;
        $result = $action->handle($db);

        $this->assertSame('mongo-started', $result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function it_calls_start_mysql()
    {
        $db = $this->fakeDatabase(StandaloneMysql::class);

        StartMysql::shouldRun()
            ->once()
            ->with($db)
            ->andReturn('mysql-started');

        $action = new StartDatabase;
        $result = $action->handle($db);

        $this->assertSame('mysql-started', $result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function it_calls_start_mariadb()
    {
        $db = $this->fakeDatabase(StandaloneMariadb::class);

        StartMariadb::shouldRun()
            ->once()
            ->with($db)
            ->andReturn('mariadb-started');

        $action = new StartDatabase;
        $result = $action->handle($db);

        $this->assertSame('mariadb-started', $result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function it_calls_start_keydb()
    {
        $db = $this->fakeDatabase(StandaloneKeydb::class);

        StartKeydb::shouldRun()
            ->once()
            ->with($db)
            ->andReturn('keydb-started');

        $action = new StartDatabase;
        $result = $action->handle($db);

        $this->assertSame('keydb-started', $result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function it_calls_start_dragonfly()
    {
        $db = $this->fakeDatabase(StandaloneDragonfly::class);

        StartDragonfly::shouldRun()
            ->once()
            ->with($db)
            ->andReturn('dragonfly-started');

        $action = new StartDatabase;
        $result = $action->handle($db);

        $this->assertSame('dragonfly-started', $result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function it_calls_start_clickhouse()
    {
        $db = $this->fakeDatabase(StandaloneClickhouse::class);

        StartClickhouse::shouldRun()
            ->once()
            ->with($db)
            ->andReturn('clickhouse-started');

        $action = new StartDatabase;
        $result = $action->handle($db);

        $this->assertSame('clickhouse-started', $result);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function it_dispatches_start_database_proxy_when_public()
    {
        Bus::fake();

        $db = $this->fakeDatabase(StandaloneRedis::class);
        $db->is_public = true;
        $db->public_port = 6379;

        StartRedis::shouldRun()
            ->once()
            ->with($db)
            ->andReturn('redis-started');

        $action = new StartDatabase;
        $action->handle($db);

        // StartDatabaseProxy doesn't implement ShouldQueue itself, but Lorisleiva always
        // dispatches actions wrapped in a JobDecorator (which does implement ShouldQueue).
        // JobDecorator wraps the action via composition rather than extending it, so a
        // plain class-string match against StartDatabaseProxy would never succeed —
        // JobDecorator::decorates() is the intended way to check which action it wraps.
        Bus::assertDispatched(function (JobDecorator $job) {
            return $job->decorates(StartDatabaseProxy::class);
        });
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function it_throws_for_unsupported_database_type()
    {
        $db = $this->fakeDatabase(StandaloneRedis::class, morphClass: 'UnknownClass');

        $action = new StartDatabase;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported database type.');

        $action->handle($db);
    }
}
