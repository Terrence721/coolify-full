<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Application;

use App\Actions\Application\StopApplication;
use App\Events\ServiceStatusChanged;
use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Fakes\RemoteProcessFake;
use Tests\TestCase;

require_once __DIR__.'/../../Support/Fakes/action_remote_process_overrides.php';

class StopApplicationTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * Applications reach $application->environment->project->team->id at the end of
     * StopApplication::handle(), so a real, persisted Team -> Project -> Environment
     * chain is needed rather than an unpersisted/mocked Application.
     */
    private function createApplicationWithTeamChain(array $attributes = []): Application
    {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);

        return Application::factory()->create([
            ...$attributes,
            'environment_id' => $environment->id,
        ]);
    }

    private function mockSettings(int $stopGracePeriodSeconds): ApplicationSetting
    {
        $settings = $this->createStub(ApplicationSetting::class);
        $settings->method('stopGracePeriodSeconds')->willReturn($stopGracePeriodSeconds);

        return $settings;
    }

    #[Test]
    public function it_fails_when_server_is_not_functional()
    {
        $server = $this->mockServer(false);

        $destination = StandaloneDocker::factory()->make();
        $destination->setRelation('server', $server);

        $application = Application::factory()->make();
        $application->setRelation('destination', $destination);

        $action = new StopApplication;
        $result = $action->handle($application);

        $this->assertSame('Server is not functional', $result);
    }

    #[Test]
    public function it_removes_swarm_stack_and_returns_null()
    {
        Bus::fake();

        $server = $this->mockServer(true, true);

        $destination = new SwarmDocker([
            'server_id' => 1,
            'name' => 'test-swarm',
            'network' => 'coolify',
        ]);
        $destination->setRelation('server', $server);

        $application = Application::factory()->make([
            'uuid' => 'app-uuid',
        ]);
        $application->setRelation('destination', $destination);

        $action = new StopApplication;
        $result = $action->handle($application);

        $this->assertNull($result);
    }

    #[Test]
    public function it_stops_and_removes_containers_for_standalone()
    {
        $server = $this->mockServer(true, false);

        $destination = StandaloneDocker::factory()->make();
        $destination->setRelation('server', $server);

        $application = $this->createApplicationWithTeamChain([
            'build_pack' => 'dockercompose',
        ]);
        $application->setRelation('destination', $destination);
        $application->setRelation('settings', $this->mockSettings(5));

        RemoteProcessFake::$containers = collect([
            ['Names' => 'container1'],
            ['Names' => 'container2'],
        ]);

        // Faked after the DB chain above, since Event::fake() replaces the real
        // dispatcher and would silently break BaseModel's auto-UUID creating hook.
        Bus::fake();
        Event::fake();

        $action = new StopApplication;
        // CleanupDocker doesn't implement ShouldQueue, so AsAction::dispatch() runs it
        // synchronously rather than through the Bus (Bus::fake() can't intercept it, and
        // running it for real here would attempt a real SSH connection) — skip it.
        $result = $action->handle($application, dockerCleanup: false);

        $this->assertNull($result);

        Event::assertDispatched(ServiceStatusChanged::class);
    }

    #[Test]
    public function it_stops_preview_containers_when_preview_deployments_true()
    {
        $server = $this->mockServer(true, false);

        $destination = StandaloneDocker::factory()->make();
        $destination->setRelation('server', $server);

        $application = $this->createApplicationWithTeamChain();
        $application->setRelation('destination', $destination);
        $application->setRelation('settings', $this->mockSettings(3));

        RemoteProcessFake::$containers = collect([
            ['Names' => 'preview-container'],
        ]);

        Bus::fake();
        Event::fake();

        $action = new StopApplication;
        $result = $action->handle($application, previewDeployments: true);

        $this->assertNull($result);
        Event::assertDispatched(ServiceStatusChanged::class);
    }

    #[Test]
    public function it_updates_restart_count_when_reset_restart_count_true()
    {
        $server = $this->mockServer(true, false);

        $destination = StandaloneDocker::factory()->make();
        $destination->setRelation('server', $server);

        $application = $this->createApplicationWithTeamChain([
            'restart_count' => 5,
            'status' => 'running',
        ]);
        $application->setRelation('destination', $destination);
        $application->setRelation('settings', $this->mockSettings(1));

        Bus::fake();
        Event::fake();

        $action = new StopApplication;
        $action->handle($application, resetRestartCount: true);

        $application->refresh();

        $this->assertSame(0, $application->restart_count);
        $this->assertNull($application->last_restart_at);
        $this->assertNull($application->last_restart_type);
    }

    #[Test]
    public function it_sets_status_exited_when_reset_restart_count_false()
    {
        $server = $this->mockServer(true, false);

        $destination = StandaloneDocker::factory()->make();
        $destination->setRelation('server', $server);

        $application = $this->createApplicationWithTeamChain([
            'status' => 'running',
        ]);
        $application->setRelation('destination', $destination);
        $application->setRelation('settings', $this->mockSettings(1));

        Bus::fake();
        Event::fake();

        $action = new StopApplication;
        $action->handle($application, resetRestartCount: false);

        $application->refresh();

        // Application::status()'s mutator normalizes plain values into "status:health".
        $this->assertSame('exited:unhealthy', $application->status);
    }

    #[Test]
    public function it_returns_exception_message_when_error_occurs()
    {
        $server = $this->mockServer(true, false);

        $destination = StandaloneDocker::factory()->make();
        $destination->setRelation('server', $server);

        $application = Application::factory()->make();
        $application->setRelation('destination', $destination);

        RemoteProcessFake::$containersException = new \Exception('Boom');

        $action = new StopApplication;
        $result = $action->handle($application);

        $this->assertSame('Boom', $result);
    }
}
