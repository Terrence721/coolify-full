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
}
