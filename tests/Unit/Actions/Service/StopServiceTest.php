<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Service;

use App\Actions\Service\StopService;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

require_once __DIR__.'/../../../Support/Fakes/service_action_remote_process_overrides.php';

/**
 * Regression coverage for a real bug found 2026-07-31 (code review, issue #70): handle() stops
 * and removes every application/database container on the server, but never updates the
 * corresponding ServiceApplication/ServiceDatabase status column - inherited unchanged from the
 * original upstream import. Service::getStatusAttribute() aggregates its status live from those
 * child rows, so a stopped service kept showing its pre-stop status (e.g. "running") until the
 * next independent GetContainersStatus poll happened to correct it. Same root shape as
 * StopApplication.php's already-covered fix (StopApplicationTest.php), just for the
 * service/sub-resource side of the codebase instead of the application side.
 */
class StopServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeFunctionalService(): Service
    {
        $team = Team::factory()->create();
        $server = Server::factory()->create(['team_id' => $team->id]);
        $server->settings->update(['is_reachable' => true, 'is_usable' => true]);
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = $project->environments()->first();
        $destination = $server->destinations()->first();

        return Service::factory()->create([
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $destination->id,
        ]);
    }

    #[Test]
    public function it_marks_applications_and_databases_exited_after_stopping_their_containers(): void
    {
        $service = $this->makeFunctionalService();
        $application = ServiceApplication::factory()->create([
            'service_id' => $service->id,
            'status' => 'running:healthy',
        ]);
        $database = ServiceDatabase::factory()->create([
            'service_id' => $service->id,
            'status' => 'running:healthy',
        ]);

        Event::fake();

        $result = StopService::run($service, dockerCleanup: false);

        $this->assertNull($result);
        $this->assertSame('exited', $application->fresh()->status);
        $this->assertSame('exited', $database->fresh()->status);
    }

    #[Test]
    public function it_does_not_error_when_the_service_has_no_applications_or_databases(): void
    {
        $service = $this->makeFunctionalService();

        Event::fake();

        $result = StopService::run($service, dockerCleanup: false);

        $this->assertNull($result);
    }

    /**
     * Regression test for a real bug: when a server is deleted with "delete all resources"
     * checked, the controller queues DeleteResourceJob for each resource *then* soft-deletes
     * the Server row synchronously, before the queued job (which calls StopService) ever runs -
     * typically in a separate queue-worker process whose Server identity map
     * (StandaloneDocker::getServerAttribute() routes through Server::findCached()) never held
     * this server to begin with. destination->server resolves to null in that fresh-process
     * scenario - previously this crashed with an uncaught Error ("Call to a member function
     * isFunctional() on null"), never actually reaching the server to stop its real containers,
     * while DeleteResourceJob's finally block force-deleted the resource anyway.
     *
     * Deliberately deletes $server directly (never accessed via ->destination->server first) so
     * this test reproduces that fresh-process scenario rather than the identity-map-staleness
     * scenario covered separately by ServerIdentityMapTest.php - accessing the accessor before
     * deleting would populate the cache and mask this exact regression.
     */
    #[Test]
    public function it_still_reaches_a_soft_deleted_servers_containers(): void
    {
        $team = Team::factory()->create();
        $server = Server::factory()->create(['team_id' => $team->id]);
        $server->settings->update(['is_reachable' => true, 'is_usable' => true]);
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = $project->environments()->first();
        $destination = $server->destinations()->first();

        $service = Service::factory()->create([
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $destination->id,
        ]);
        $application = ServiceApplication::factory()->create([
            'service_id' => $service->id,
            'status' => 'running:healthy',
        ]);

        $server->delete();
        $this->assertTrue($server->fresh()->trashed());

        Event::fake();

        $result = StopService::run($service->fresh(), dockerCleanup: false);

        $this->assertNull($result);
        $this->assertSame('exited', $application->fresh()->status);
    }
}
