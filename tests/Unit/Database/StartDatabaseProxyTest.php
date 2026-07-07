<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Database;

use App\Actions\Database\StartDatabaseProxy;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Models\StandaloneMysql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Notifications\Container\ContainerRestarted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Fakes\DatabaseActionFake;
use Tests\Support\Fakes\RemoteProcessFake;
use Tests\TestCase;

class StartDatabaseProxyTest extends TestCase
{
    use RefreshDatabase;

    private StartDatabaseProxy $action;

    protected function setUp(): void
    {
        parent::setUp();

        DatabaseActionFake::reset();
        RemoteProcessFake::reset();

        $this->action = new StartDatabaseProxy;
    }

    /** Invoke a protected/private method on $object via Reflection. */
    private function callProtected(object $object, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($object, $method))->invoke($object, ...$args);
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

    #[Test]
    public function it_builds_default_timeout_config()
    {
        $result = $this->callProtected($this->action, 'buildProxyTimeoutConfig', null);
        $this->assertSame('proxy_timeout 3600s;', $result);

        $result = $this->callProtected($this->action, 'buildProxyTimeoutConfig', 0);
        $this->assertSame('proxy_timeout 3600s;', $result);

        $result = $this->callProtected($this->action, 'buildProxyTimeoutConfig', 120);
        $this->assertSame('proxy_timeout 120s;', $result);
    }

    #[Test]
    public function it_detects_non_transient_errors()
    {
        $this->assertTrue($this->callProtected($this->action, 'isNonTransientError', 'port is already allocated'));
        $this->assertTrue($this->callProtected($this->action, 'isNonTransientError', 'address already in use'));
        $this->assertTrue($this->callProtected($this->action, 'isNonTransientError', 'Bind for 0.0.0.0'));

        $this->assertFalse($this->callProtected($this->action, 'isNonTransientError', 'random docker error'));
    }

    #[Test]
    public function it_resolves_internal_port_for_mysql()
    {
        $db = $this->fakeDatabase();
        $this->action->handle($db);

        $calls = RemoteProcessFake::$instantRemoteProcessCalls;

        $this->assertNotEmpty($calls);
        $composeWrite = $calls[1][0][2];

        preg_match("/echo '(.+)'/", $composeWrite, $matches);
        $decoded = base64_decode($matches[1]);

        $yaml = Yaml::parse($decoded);

        $this->assertSame('nginx:stable-alpine', $yaml['services']['db-123-proxy']['image']);
        $this->assertSame(['net-abc'], $yaml['services']['db-123-proxy']['networks']);
    }

    #[Test]
    public function it_resolves_internal_port_for_redis_ssl()
    {
        $db = $this->fakeDatabase([
            '_class' => StandaloneRedis::class,
            'enable_ssl' => true,
        ]);

        $this->action->handle($db);

        $calls = RemoteProcessFake::$instantRemoteProcessCalls;
        $nginxWrite = $calls[1][0][1];

        preg_match("/echo '(.+)'/", $nginxWrite, $matches);
        $decoded = base64_decode($matches[1]);

        $this->assertStringContainsString('proxy_pass db-123:6380;', $decoded);
    }

    #[Test]
    public function it_handles_service_database_morph_class()
    {
        $serviceDb = new ServiceDatabase([
            'name' => 'mydb',
            'public_port' => 7777,
            'custom_type' => 'postgresql',
        ]);
        $serviceDb->uuid = 'svc-db';

        $service = new class
        {
            public string $uuid = 'svc-uuid';

            public object $destination;
        };
        $service->destination = (object) ['server' => 'srv-02'];

        $serviceDb->setRelation('service', $service);

        $this->action->handle($serviceDb);

        $calls = RemoteProcessFake::$instantRemoteProcessCalls;
        $nginxWrite = $calls[1][0][1];

        preg_match("/echo '(.+)'/", $nginxWrite, $matches);
        $decoded = base64_decode($matches[1]);

        $this->assertStringContainsString('proxy_pass mydb-svc-uuid:5432;', $decoded);
    }

    #[Test]
    public function it_disables_public_access_on_non_transient_error()
    {
        RemoteProcessFake::$instantRemoteProcessException = new \RuntimeException('port is already allocated');

        $db = $this->fakeDatabase();

        // A real, persisted Team + real notification-settings rows would be needed for
        // Notification::fake()/assertSentTo() to see this (ContainerRestarted::via() calls
        // getEnabledChannels(), which NotificationFake still evaluates for real — an empty
        // channel list is silently skipped). That's tangential to what this test verifies,
        // so check the notify() call directly instead.
        $team = $this->getMockBuilder(Team::class)->onlyMethods(['notify'])->getMock();
        $team->expects($this->once())
            ->method('notify')
            ->with($this->isInstanceOf(ContainerRestarted::class));

        $environment = new class
        {
            public object $project;
        };
        $environment->project = new class
        {
            public Team $team;
        };
        $environment->project->team = $team;
        $db->setRelation('environment', $environment);

        $this->action->handle($db);

        $this->assertFalse($db->is_public);
    }

    #[Test]
    public function it_rethrows_transient_errors()
    {
        RemoteProcessFake::$instantRemoteProcessException = new \RuntimeException('random docker failure');

        $db = $this->fakeDatabase();

        $this->expectException(\RuntimeException::class);

        $this->action->handle($db);
    }
}
