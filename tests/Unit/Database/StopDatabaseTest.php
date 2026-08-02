<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Database;

use App\Actions\Database\StopDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneMysql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Fakes\RemoteProcessFake;
use Tests\TestCase;

require_once __DIR__.'/../../Support/Fakes/database_action_overrides.php';

/**
 * Regression coverage for a real bug found 2026-08-01 (code review, issue #70): handle() stops
 * and removes the database container, but never wrote status back to 'exited' - inherited
 * unchanged from the original upstream import. DatabasesController::action_deploy()/
 * action_stop() gate on this same live status column ("Database is already running."/"...already
 * stopped."), so a stop followed shortly by a legitimate start call could be wrongly rejected
 * with a 400 while the container was, in reality, already stopped - since the column only got
 * corrected by the next independent GetContainersStatus poll. Same root shape as
 * StopApplication.php/StopService.php's already-covered fix (PR #95).
 */
class StopDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private function makeFunctionalDatabase(string $status = 'running:healthy'): StandaloneMysql
    {
        $team = Team::factory()->create();
        $server = Server::factory()->create(['team_id' => $team->id]);
        $server->settings->update(['is_reachable' => true, 'is_usable' => true]);
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = $project->environments()->first();
        $destination = $server->standaloneDockers()->first();

        return StandaloneMysql::factory()->create([
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'status' => $status,
            'restart_count' => 5,
        ]);
    }

    #[Test]
    public function it_marks_the_database_exited_after_stopping_its_container(): void
    {
        RemoteProcessFake::reset();
        $database = $this->makeFunctionalDatabase();

        Event::fake();

        $result = StopDatabase::run($database, dockerCleanup: false);

        $this->assertSame('Database stopped successfully', $result);
        $this->assertSame('exited:unhealthy', $database->fresh()->status);
    }

    #[Test]
    public function it_still_resets_restart_tracking_alongside_the_status_write(): void
    {
        RemoteProcessFake::reset();
        $database = $this->makeFunctionalDatabase();

        Event::fake();

        StopDatabase::run($database, dockerCleanup: false);

        $fresh = $database->fresh();
        $this->assertSame(0, $fresh->restart_count);
        $this->assertNull($fresh->last_restart_at);
        $this->assertNull($fresh->last_restart_type);
    }

    #[Test]
    public function it_returns_early_without_writing_status_when_the_server_is_not_functional(): void
    {
        $database = $this->makeFunctionalDatabase();
        $database->destination->server->settings->update(['is_reachable' => false]);

        Event::fake();

        $result = StopDatabase::run($database, dockerCleanup: false);

        $this->assertSame('Server is not functional', $result);
        $this->assertSame('running:healthy', $database->fresh()->status);
    }

    /**
     * Regression test for a real bug: when a server is deleted with "delete all resources"
     * checked, the controller queues DeleteResourceJob for each resource *then* soft-deletes
     * the Server row synchronously, before the queued job (which calls StopDatabase) ever runs -
     * typically in a separate queue-worker process whose Server identity map
     * (StandaloneDocker::getServerAttribute() routes through Server::findCached()) never held
     * this server to begin with. destination->server resolves to null in that fresh-process
     * scenario - previously this crashed with an uncaught Error ("Call to a member function
     * isFunctional() on null"), never actually reaching the server to stop its real container,
     * while DeleteResourceJob's finally block force-deleted the resource anyway.
     *
     * Deliberately deletes $server directly (never accessed via ->destination->server first) so
     * this test reproduces that fresh-process scenario rather than the identity-map-staleness
     * scenario covered separately by ServerIdentityMapTest.php - accessing the accessor before
     * deleting would populate the cache and mask this exact regression.
     */
    #[Test]
    public function it_still_reaches_a_soft_deleted_servers_container(): void
    {
        RemoteProcessFake::reset();
        $team = Team::factory()->create();
        $server = Server::factory()->create(['team_id' => $team->id]);
        $server->settings->update(['is_reachable' => true, 'is_usable' => true]);
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = $project->environments()->first();
        $destination = $server->standaloneDockers()->first();

        $database = StandaloneMysql::factory()->create([
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'status' => 'running:healthy',
            'restart_count' => 5,
        ]);

        $server->delete();
        $this->assertTrue($server->fresh()->trashed());

        Event::fake();

        $result = StopDatabase::run($database->fresh(), dockerCleanup: false);

        $this->assertSame('Database stopped successfully', $result);
        $this->assertNotEmpty(RemoteProcessFake::$instantRemoteProcessCalls);
    }
}
