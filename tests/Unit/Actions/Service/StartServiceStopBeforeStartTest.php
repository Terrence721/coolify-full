<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Service;

use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

require_once __DIR__.'/../../../Support/Fakes/service_action_remote_process_overrides.php';

/**
 * Regression coverage for a real bug found 2026-07-31 (code review, issue #70):
 * shouldStopBeforeStarting() silently cancelled the caller's explicit stopBeforeStart: true
 * whenever pullLatestImages was also true - reachable via the real, OpenAPI-documented
 * POST /api/v1/services/{uuid}/restart?latest=true endpoint (RestartService always passes
 * stopBeforeStart: true). The concrete consequence: StopService::handle() is the only place
 * that cancels stale in_progress/queued Activity records for a service, so skipping it left
 * a genuinely stuck prior deployment stuck forever after a restart-with-latest-images.
 */
class StartServiceStopBeforeStartTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(): Service
    {
        $team = Team::factory()->create();
        $server = Server::factory()->create(['team_id' => $team->id]);
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = $project->environments()->first();
        $destination = $server->destinations()->first();

        $service = Service::factory()->create([
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $destination->id,
        ]);

        // Stub out the real Docker/SSH-touching parts of handle() (already covered by other
        // suites) to isolate the stopBeforeStart control-flow logic being tested here.
        $service = Mockery::mock($service)->makePartial();
        $service->shouldReceive('parse')->andReturn(collect([]));
        $service->shouldReceive('saveComposeConfigs')->andReturnNull();
        $service->shouldReceive('isConfigurationChanged')->andReturn(false);
        $service->shouldReceive('workdir')->andReturn('/tmp/start-service-test-workdir');
        $service->shouldReceive('networks')->andReturn(collect([]));

        return $service;
    }

    #[Test]
    public function stop_before_start_runs_stop_service_even_when_also_pulling_latest_images(): void
    {
        $service = $this->makeService();

        $mockStop = Mockery::mock(StopService::class);
        $mockStop->shouldReceive('handle')->once()->with($service, false, false);
        $this->instance(StopService::class, $mockStop);

        StartService::run($service, pullLatestImages: true, stopBeforeStart: true);
    }

    #[Test]
    public function does_not_run_stop_service_when_stop_before_start_is_false(): void
    {
        $service = $this->makeService();

        $mockStop = Mockery::mock(StopService::class);
        $mockStop->shouldNotReceive('handle');
        $this->instance(StopService::class, $mockStop);

        StartService::run($service, pullLatestImages: true, stopBeforeStart: false);
    }

    #[Test]
    public function stop_before_start_runs_stop_service_when_not_pulling_latest_images(): void
    {
        $service = $this->makeService();

        $mockStop = Mockery::mock(StopService::class);
        $mockStop->shouldReceive('handle')->once()->with($service, false, false);
        $this->instance(StopService::class, $mockStop);

        StartService::run($service, pullLatestImages: false, stopBeforeStart: true);
    }
}
