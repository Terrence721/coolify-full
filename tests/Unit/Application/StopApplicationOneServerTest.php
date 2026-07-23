<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Application;

use App\Actions\Application\StopApplicationOneServer;
use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Server;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Fakes\RemoteProcessFake;
use Tests\TestCase;

require_once __DIR__.'/../../Support/Fakes/action_remote_process_overrides.php';

class StopApplicationOneServerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RemoteProcessFake::reset();
    }

    private function mockServer(bool $functional = true, bool $swarm = false): Server
    {
        $server = $this->createStub(Server::class);
        $server->method('isFunctional')->willReturn($functional);
        $server->method('isSwarm')->willReturn($swarm);

        return $server;
    }

    private function mockSettings(int $stopGracePeriodSeconds): ApplicationSetting
    {
        $settings = $this->createStub(ApplicationSetting::class);
        $settings->method('stopGracePeriodSeconds')->willReturn($stopGracePeriodSeconds);

        return $settings;
    }

    #[Test]
    public function it_returns_null_immediately_if_destination_server_is_swarm()
    {
        $server = $this->mockServer(true, true);

        $destination = new class
        {
            public Server $server;
        };
        $destination->server = $server;

        $application = Application::factory()->make();
        $application->setRelation('destination', $destination);

        $action = new StopApplicationOneServer;
        $result = $action->handle($application, $server);

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_error_if_server_not_functional()
    {
        $server = $this->mockServer(false, false);

        $destination = new class
        {
            public Server $server;
        };
        $destination->server = $server;

        $application = Application::factory()->make();
        $application->setRelation('destination', $destination);

        $action = new StopApplicationOneServer;
        $result = $action->handle($application, $server);

        $this->assertSame('Server is not functional', $result);
    }

    #[Test]
    public function it_stops_and_removes_containers()
    {
        $server = $this->mockServer(true, false);

        $destination = new class
        {
            public Server $server;
        };
        $destination->server = $server;

        $application = Application::factory()->make([
            'id' => 10,
        ]);
        $application->setRelation('destination', $destination);
        $application->setRelation('settings', $this->mockSettings(7));

        RemoteProcessFake::$containers = collect([
            ['Names' => 'containerA'],
            ['Names' => 'containerB'],
        ]);

        $action = new StopApplicationOneServer;
        $result = $action->handle($application, $server);

        $this->assertNull($result);

        $executed = RemoteProcessFake::$instantRemoteProcessCalls;
        $this->assertCount(2, $executed);

        $this->assertSame(
            [
                'docker stop --time=7 containerA',
                'docker rm -f containerA',
            ],
            $executed[0][0]
        );

        $this->assertSame(
            [
                'docker stop --time=7 containerB',
                'docker rm -f containerB',
            ],
            $executed[1][0]
        );
    }

    #[Test]
    public function it_returns_null_when_no_containers()
    {
        $server = $this->mockServer(true, false);

        $destination = new class
        {
            public Server $server;
        };
        $destination->server = $server;

        $application = Application::factory()->make([
            'id' => 10,
        ]);
        $application->setRelation('destination', $destination);
        $application->setRelation('settings', $this->mockSettings(5));

        $action = new StopApplicationOneServer;
        $result = $action->handle($application, $server);

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_exception_message_when_error_occurs()
    {
        $server = $this->mockServer(true, false);

        $destination = new class
        {
            public Server $server;
        };
        $destination->server = $server;

        $application = Application::factory()->make([
            'id' => 10,
        ]);
        $application->setRelation('destination', $destination);
        $application->setRelation('settings', $this->mockSettings(5));

        RemoteProcessFake::$containersException = new \Exception('Boom');

        $action = new StopApplicationOneServer;
        $result = $action->handle($application, $server);

        $this->assertSame('Boom', $result);
    }
}
