<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Application/Service denormalize team_id from environment->project->team so
 * ownedByCurrentTeam() can use a direct indexed column instead of a 3-hop
 * whereRelation() EXISTS chain (see todo.md card #8). These tests prove the
 * sync hook actually keeps team_id correct, and that ownedByCurrentTeam()
 * still scopes correctly once switched to the direct column.
 */
class TeamScopingTest extends TestCase
{
    use RefreshDatabase;

    private function makeEnvironmentForTeam(Team $team): Environment
    {
        $project = Project::factory()->create(['team_id' => $team->id]);

        return Environment::factory()->create(['project_id' => $project->id]);
    }

    #[Test]
    public function application_team_id_is_synced_from_its_environment_on_create()
    {
        $team = Team::factory()->create();
        $environment = $this->makeEnvironmentForTeam($team);

        $application = Application::factory()->create(['environment_id' => $environment->id]);

        $this->assertSame($team->id, $application->fresh()->team_id);
    }

    #[Test]
    public function application_team_id_is_resynced_when_environment_id_changes()
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $application = Application::factory()->create(['environment_id' => $this->makeEnvironmentForTeam($teamA)->id]);

        $application->environment_id = $this->makeEnvironmentForTeam($teamB)->id;
        $application->save();

        $this->assertSame($teamB->id, $application->fresh()->team_id);
    }

    #[Test]
    public function application_owned_by_current_team_only_returns_that_teams_applications()
    {
        $team = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => 'admin']);
        session(['currentTeam' => $team]);
        $this->actingAs($user);

        $ownApplication = Application::factory()->create(['environment_id' => $this->makeEnvironmentForTeam($team)->id]);
        Application::factory()->create(['environment_id' => $this->makeEnvironmentForTeam($otherTeam)->id]);

        $result = Application::ownedByCurrentTeam()->pluck('id');

        $this->assertTrue($result->contains($ownApplication->id));
        $this->assertCount(1, $result);
    }

    #[Test]
    public function service_team_id_is_synced_from_its_environment_on_create()
    {
        $team = Team::factory()->create();
        $environment = $this->makeEnvironmentForTeam($team);

        $service = Service::factory()->create(['environment_id' => $environment->id]);

        $this->assertSame($team->id, $service->fresh()->team_id);
    }

    #[Test]
    public function service_owned_by_current_team_only_returns_that_teams_services()
    {
        $team = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => 'admin']);
        session(['currentTeam' => $team]);
        $this->actingAs($user);

        $ownService = Service::factory()->create(['environment_id' => $this->makeEnvironmentForTeam($team)->id]);
        Service::factory()->create(['environment_id' => $this->makeEnvironmentForTeam($otherTeam)->id]);

        $result = Service::ownedByCurrentTeam()->pluck('id');

        $this->assertTrue($result->contains($ownService->id));
        $this->assertCount(1, $result);
    }
}
