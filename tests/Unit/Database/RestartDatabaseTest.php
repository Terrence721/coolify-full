<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Database;

use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Models\Server;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RestartDatabaseTest extends TestCase
{
    /**
     * @param  class-string  $class
     */
    private function mockDatabase(string $class, bool $functional = true): mixed
    {
        $server = $this->createStub(Server::class);
        $server->method('isFunctional')->willReturn($functional);

        $destination = new class($server)
        {
            public function __construct(public Server $server) {}
        };

        // A real (not mocked) model instance is required here — PHPUnit's createMock()
        // stubs out every public method by default, including setRelation(), so the
        // relation would never actually get cached and ->destination would stay null.
        // None of these 8 Standalone* models actually have a factory class despite
        // using HasFactory, so build a bare instance directly.
        $db = new $class;
        $db->setRelation('destination', $destination);

        return $db;
    }

    #[Test]
    public function it_returns_error_when_server_not_functional()
    {
        $db = $this->mockDatabase(StandaloneRedis::class, functional: false);

        $action = new RestartDatabase;
        $result = $action->handle($db);

        $this->assertSame('Server is not functional', $result);
    }

    #[Test]
    public function it_calls_stop_and_start_database()
    {
        $db = $this->mockDatabase(StandaloneMysql::class);

        StopDatabase::shouldRun()
            ->once()
            ->with($db, false);

        StartDatabase::shouldRun()
            ->once()
            ->with($db)
            ->andReturn('started');

        $action = new RestartDatabase;
        $result = $action->handle($db);

        $this->assertSame('started', $result);
    }

    #[Test]
    public function it_works_for_all_supported_database_types()
    {
        $classes = [
            StandaloneRedis::class,
            StandalonePostgresql::class,
            StandaloneMongodb::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
            StandaloneKeydb::class,
            StandaloneDragonfly::class,
            StandaloneClickhouse::class,
        ];

        foreach ($classes as $class) {
            $db = $this->mockDatabase($class);

            StopDatabase::shouldRun()
                ->once()
                ->with($db, false);

            StartDatabase::shouldRun()
                ->once()
                ->with($db)
                ->andReturn("started-$class");

            $action = new RestartDatabase;
            $result = $action->handle($db);

            $this->assertSame("started-$class", $result);
        }
    }

    #[Test]
    public function it_propagates_start_database_return_value()
    {
        $db = $this->mockDatabase(StandalonePostgresql::class);

        StopDatabase::shouldRun()->once()->with($db, false);
        StartDatabase::shouldRun()->once()->with($db)->andReturn(['ok' => true]);

        $action = new RestartDatabase;
        $result = $action->handle($db);

        $this->assertSame(['ok' => true], $result);
    }
}
