<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Database\StartDatabaseProxy;
use App\Models\Server;
use App\Models\StandaloneMysql;
use App\Models\StandaloneRedis;
use Tests\Support\Fakes\DatabaseActionFake;
use Tests\Support\Fakes\RemoteProcessFake;

/** Shared fixture builder + setUp() for StartDatabaseProxyTest. */
trait InteractsWithStartDatabaseProxy
{
    use CallsProtectedMethods;

    protected StartDatabaseProxy $action;

    protected function setUp(): void
    {
        parent::setUp();

        DatabaseActionFake::reset();
        RemoteProcessFake::reset();

        $this->action = new StartDatabaseProxy;
    }

    private function fakeDatabase(array $overrides = []): StandaloneMysql|StandaloneRedis
    {
        $class = $overrides['_class'] ?? StandaloneMysql::class;
        unset($overrides['_class']);

        $db = new $class(array_merge([
            'uuid' => 'db-123',
            'public_port' => 5555,
            'enable_ssl' => false,
        ], $overrides));

        // Model::update() is a no-op on a non-persisted model ($this->exists is false),
        // so the error-handling test's $database->update(['is_public' => false]) call
        // would silently do nothing. Marking it as "existing" (with the table present via
        // RefreshDatabase) lets the UPDATE query run for real — it affects zero rows since
        // no matching id exists, but Eloquent still applies the attribute change in memory.
        $db->id = 999;
        $db->exists = true;

        $destination = new class($this->createStub(Server::class))
        {
            public string $network = 'net-abc';

            public function __construct(public Server $server) {}
        };
        $db->setRelation('destination', $destination);

        return $db;
    }
}
