<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Application;

use App\Actions\Application\CleanupPreviewDeployment;
use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Fakes\RemoteProcessFake;
use Tests\TestCase;

require_once __DIR__.'/../../Support/Fakes/action_remote_process_overrides.php';

class CleanupPreviewDeploymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RemoteProcessFake::reset();
    }

    #[Test]
    public function it_fails_when_application_has_no_destination()
    {
        $application = Application::factory()->make([
            'destination_id' => null,
        ]);

        $action = new CleanupPreviewDeployment;
        $result = $action->handle($application, 123);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('Application has no destination', $result['message']);
    }

    #[Test]
    public function it_fails_when_server_is_not_functional()
    {
        $server = Server::factory()->make([
            'id' => 10,
        ]);

        $server->setRelation('settings', new ServerSetting([
            'is_reachable' => false,
            'is_usable' => false,
            'force_disabled' => false,
        ]));

        $destination = StandaloneDocker::factory()->make();
        $destination->setRelation('server', $server);

        $application = Application::factory()->make();
        $application->setRelation('destination', $destination);

        $action = new CleanupPreviewDeployment;
        $result = $action->handle($application, 123);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('Server is not functional', $result['message']);
    }

    #[Test]
    public function it_cancels_active_deployments()
    {
        Bus::fake();

        $server = Server::factory()->make();
        $server->setRelation('settings', new ServerSetting([
            'is_reachable' => true,
            'is_usable' => true,
            'force_disabled' => false,
            'is_swarm_manager' => false,
            'is_swarm_worker' => false,
        ]));

        $destination = StandaloneDocker::factory()->make();
        $destination->setRelation('server', $server);

        $application = Application::factory()->create();
        $application->setRelation('destination', $destination);

        // Active deployments
        $d1 = ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'deployment_uuid' => (string) Str::uuid(),
            'pull_request_id' => 99,
            'status' => ApplicationDeploymentStatus::QUEUED->value,
        ]);

        $d2 = ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'deployment_uuid' => (string) Str::uuid(),
            'pull_request_id' => 99,
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        $action = new CleanupPreviewDeployment;
        $result = $action->handle($application, 99);

        $this->assertSame(2, $result['cancelled_deployments']);

        $this->assertSame(
            ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
            $d1->fresh()->status
        );
        $this->assertSame(
            ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
            $d2->fresh()->status
        );
    }

    #[Test]
    public function it_kills_swarm_containers()
    {
        Bus::fake();

        $server = Server::factory()->make();
        $server->setRelation('settings', new ServerSetting([
            'is_reachable' => true,
            'is_usable' => true,
            'force_disabled' => false,
            'is_swarm_manager' => true,
            'is_swarm_worker' => false,
        ]));

        $destination = new SwarmDocker([
            'server_id' => 1,
            'name' => 'test-swarm',
            'network' => 'coolify',
        ]);
        $destination->setRelation('server', $server);

        $application = Application::factory()->create([
            'uuid' => 'app-uuid',
        ]);
        $application->setRelation('destination', $destination);

        $action = new CleanupPreviewDeployment;
        $result = $action->handle($application, 55);

        $this->assertSame(1, $result['killed_containers']);
    }

    #[Test]
    public function it_kills_standalone_containers()
    {
        Bus::fake();

        $server = Server::factory()->make();
        $server->setRelation('settings', new ServerSetting([
            'is_reachable' => true,
            'is_usable' => true,
            'force_disabled' => false,
            'is_swarm_manager' => false,
            'is_swarm_worker' => false,
        ]));

        $destination = StandaloneDocker::factory()->make();
        $destination->setRelation('server', $server);

        $application = Application::factory()->create();
        $application->setRelation('destination', $destination);

        RemoteProcessFake::$containers = collect([
            ['Names' => 'container1'],
            ['Names' => 'container2'],
        ]);

        $action = new CleanupPreviewDeployment;
        $result = $action->handle($application, 77);

        $this->assertSame(2, $result['killed_containers']);
    }

    #[Test]
    public function it_dispatches_delete_resource_job_when_preview_exists()
    {
        Bus::fake();

        $server = Server::factory()->make();
        $server->setRelation('settings', new ServerSetting([
            'is_reachable' => true,
            'is_usable' => true,
            'force_disabled' => false,
            'is_swarm_manager' => false,
            'is_swarm_worker' => false,
        ]));

        $destination = StandaloneDocker::factory()->make();
        $destination->setRelation('server', $server);

        $application = Application::factory()->create();
        $application->setRelation('destination', $destination);

        $preview = ApplicationPreview::create([
            'application_id' => $application->id,
            'pull_request_id' => 88,
            'pull_request_html_url' => 'https://github.com/example/repo/pull/88',
        ]);

        $action = new CleanupPreviewDeployment;
        $action->handle($application, 88, $preview);

        Bus::assertDispatched(DeleteResourceJob::class);
    }

    #[Test]
    public function it_auto_finds_preview_when_not_provided()
    {
        Bus::fake();

        $server = Server::factory()->make();
        $server->setRelation('settings', new ServerSetting([
            'is_reachable' => true,
            'is_usable' => true,
            'force_disabled' => false,
            'is_swarm_manager' => false,
            'is_swarm_worker' => false,
        ]));

        $destination = StandaloneDocker::factory()->make();
        $destination->setRelation('server', $server);

        $application = Application::factory()->create();
        $application->setRelation('destination', $destination);

        ApplicationPreview::create([
            'application_id' => $application->id,
            'pull_request_id' => 123,
            'pull_request_html_url' => 'https://github.com/example/repo/pull/123',
        ]);

        $action = new CleanupPreviewDeployment;
        $action->handle($application, 123);

        Bus::assertDispatched(DeleteResourceJob::class);
    }
}
