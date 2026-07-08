<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Shared fixture builder for StartDatabaseTest, which verifies that
 * App\Actions\Database\StartDatabase dispatches to the right engine-specific
 * action based on the database model's morph class.
 */
trait InteractsWithStartDatabaseDispatch
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

            public function __construct(public Server $server)
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
}
