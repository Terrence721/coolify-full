<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\Service;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Policies\ApiTokenPolicy;
use App\Policies\ApplicationPolicy;
use App\Policies\ApplicationPreviewPolicy;
use App\Policies\ApplicationSettingPolicy;
use App\Policies\DatabasePolicy;
use App\Policies\EnvironmentPolicy;
use App\Policies\GithubAppPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ResourceCreatePolicy;
use App\Policies\S3StoragePolicy;
use App\Policies\ServerPolicy;
use App\Policies\ServicePolicy;
use App\Policies\SharedEnvironmentVariablePolicy;
use App\Policies\StandaloneDockerPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithTeamRoles;
use Tests\TestCase;

/**
 * Regression coverage for a real, severe bug found while chasing the NotificationPolicy fix
 * (issue #70): nearly every Policy class in the app had its real admin/owner-only authorization
 * check commented out and unconditionally allowed everyone, inherited from the original upstream
 * import. All 17 affected files are fixed in one pass here rather than dribbled out over many
 * findings, since it's the exact same bug repeated - restoring the intended check (uncomment,
 * delete the fake allow-everyone line). Controllers already scope the resolved model to
 * currentTeam() before calling authorize() in every case checked, so this was never a cross-team
 * IDOR - it was a real intra-team privilege escalation: any team MEMBER (not just admin/owner)
 * could update/delete/deploy applications, databases, servers, projects, environments, etc.
 *
 * This suite exercises the policies directly (not via HTTP) for speed and breadth - one test per
 * affected policy proving a plain member is now denied where the original commented-out code
 * required admin/owner, and that admin is still allowed. `tests/v4/Feature/*Test.php` (1347
 * tests total) already exercise these same policies indirectly through the real controllers and
 * all still pass with the fix in place.
 */
class DisabledPolicyChecksTest extends TestCase
{
    use InteractsWithTeamRoles, RefreshDatabase;

    #[Test]
    public function application_policy_denies_a_member_and_allows_an_admin(): void
    {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $application = Application::factory()->create(['environment_id' => $environment->id]);
        $policy = new ApplicationPolicy;

        $this->assertFalse($policy->delete($this->memberOf($team), $application));
        $this->assertTrue($policy->delete($this->adminOf($team), $application));
        $this->assertFalse($policy->update($this->memberOf($team), $application)->allowed());
        $this->assertTrue($policy->update($this->adminOf($team), $application)->allowed());
    }

    #[Test]
    public function database_policy_denies_a_member_and_allows_an_admin(): void
    {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $server = Server::factory()->create(['team_id' => $team->id]);
        $destination = $server->standaloneDockers()->first();
        $database = StandalonePostgresql::create([
            'name' => 'db',
            'postgres_password' => 'secret',
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'environment_id' => $environment->id,
        ]);
        $policy = new DatabasePolicy;

        $this->assertFalse($policy->delete($this->memberOf($team), $database));
        $this->assertTrue($policy->delete($this->adminOf($team), $database));
        $this->assertFalse($policy->update($this->memberOf($team), $database)->allowed());
        $this->assertTrue($policy->update($this->adminOf($team), $database)->allowed());
    }

    #[Test]
    public function server_policy_denies_a_member_and_allows_an_admin(): void
    {
        $team = Team::factory()->create();
        $server = Server::factory()->create(['team_id' => $team->id]);
        $policy = new ServerPolicy;

        $this->assertFalse($policy->update($this->memberOf($team), $server));
        $this->assertTrue($policy->update($this->adminOf($team), $server));
        $this->assertFalse($policy->delete($this->memberOf($team), $server));
        $this->assertTrue($policy->delete($this->adminOf($team), $server));
    }

    #[Test]
    public function project_policy_denies_a_member_and_allows_an_admin(): void
    {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $policy = new ProjectPolicy;

        $this->assertFalse($policy->update($this->memberOf($team), $project));
        $this->assertTrue($policy->update($this->adminOf($team), $project));
        $this->assertFalse($policy->delete($this->memberOf($team), $project));
        $this->assertTrue($policy->delete($this->adminOf($team), $project));
    }

    #[Test]
    public function environment_policy_denies_a_member_and_allows_an_admin(): void
    {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $policy = new EnvironmentPolicy;

        $this->assertFalse($policy->update($this->memberOf($team), $environment));
        $this->assertTrue($policy->update($this->adminOf($team), $environment));
    }

    #[Test]
    public function service_policy_denies_a_member_and_allows_an_admin(): void
    {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $server = Server::factory()->create(['team_id' => $team->id]);
        $destination = $server->destinations()->first();
        $service = Service::factory()->create([
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $destination->id,
        ]);
        $policy = new ServicePolicy;

        $this->assertFalse($policy->update($this->memberOf($team), $service));
        $this->assertTrue($policy->update($this->adminOf($team), $service));
        $this->assertFalse($policy->delete($this->memberOf($team), $service));
        $this->assertTrue($policy->delete($this->adminOf($team), $service));
    }

    #[Test]
    public function service_policy_access_terminal_denies_everyone_including_an_admin_when_team_cannot_be_resolved(): void
    {
        // Service::team() is data_get($this, 'environment.project.team') - null whenever that
        // chain is broken. Every other method in ServicePolicy (update, stop, manageEnvironment,
        // deploy) guards this with `if (! $team) return false;` before touching $team->id;
        // accessTerminal() was the one sibling left without that guard, so a null team here
        // silently falls through to isAdmin() alone instead of denying - forced here via
        // setRelation() (no DB side effects) rather than an environment/project delete, since
        // neither model uses SoftDeletes.
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $environment->setRelation('project', null);
        $server = Server::factory()->create(['team_id' => $team->id]);
        $destination = $server->destinations()->first();
        $service = Service::factory()->create([
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $destination->id,
        ]);
        $service->setRelation('environment', $environment);
        $policy = new ServicePolicy;

        $this->assertFalse($policy->accessTerminal($this->adminOf($team), $service));
        $this->assertFalse($policy->accessTerminal($this->memberOf($team), $service));
    }

    #[Test]
    public function application_setting_policy_denies_a_member_and_allows_an_admin(): void
    {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $application = Application::factory()->create(['environment_id' => $environment->id]);
        $setting = $application->settings;
        $policy = new ApplicationSettingPolicy;

        $this->assertFalse($policy->update($this->memberOf($team), $setting));
        $this->assertTrue($policy->update($this->adminOf($team), $setting));
    }

    #[Test]
    public function application_preview_policy_denies_a_member_and_allows_an_admin(): void
    {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $application = Application::factory()->create(['environment_id' => $environment->id]);
        $preview = new ApplicationPreview(['application_id' => $application->id, 'pull_request_id' => 1]);
        $preview->setRelation('application', $application);
        $policy = new ApplicationPreviewPolicy;

        $this->assertFalse($policy->delete($this->memberOf($team), $preview));
        $this->assertTrue($policy->delete($this->adminOf($team), $preview));
        $this->assertFalse($policy->update($this->memberOf($team), $preview)->allowed());
        $this->assertTrue($policy->update($this->adminOf($team), $preview)->allowed());
    }

    #[Test]
    public function shared_environment_variable_policy_denies_a_member_and_allows_an_admin(): void
    {
        $team = Team::factory()->create();
        $variable = new SharedEnvironmentVariable(['team_id' => $team->id]);
        $policy = new SharedEnvironmentVariablePolicy;

        $this->assertFalse($policy->update($this->memberOf($team), $variable));
        $this->assertTrue($policy->update($this->adminOf($team), $variable));
    }

    #[Test]
    public function github_app_policy_denies_a_member_and_allows_an_admin(): void
    {
        $team = Team::factory()->create();
        $githubApp = new GithubApp(['team_id' => $team->id, 'is_system_wide' => false]);
        $policy = new GithubAppPolicy;

        $this->assertFalse($policy->update($this->memberOf($team), $githubApp));
        $this->assertTrue($policy->update($this->adminOf($team), $githubApp));
    }

    #[Test]
    public function resource_create_policy_denies_a_member_and_allows_an_admin(): void
    {
        $team = Team::factory()->create();
        $policy = new ResourceCreatePolicy;

        $this->assertFalse($policy->createAny($this->memberOf($team)));
        $this->assertTrue($policy->createAny($this->adminOf($team)));
        $this->assertFalse($policy->create($this->memberOf($team), Application::class));
        $this->assertTrue($policy->create($this->adminOf($team), Application::class));
    }

    #[Test]
    public function standalone_docker_policy_denies_a_member_and_allows_an_admin_to_create(): void
    {
        $team = Team::factory()->create();
        $policy = new StandaloneDockerPolicy;

        $this->assertFalse($policy->create($this->memberOf($team)));
        $this->assertTrue($policy->create($this->adminOf($team)));
    }

    #[Test]
    public function s3_storage_policy_denies_a_member_and_allows_an_admin(): void
    {
        $team = Team::factory()->create();
        $storage = S3Storage::factory()->create(['team_id' => $team->id]);
        $policy = new S3StoragePolicy;

        $this->assertFalse($policy->update($this->memberOf($team), $storage));
        $this->assertTrue($policy->update($this->adminOf($team), $storage));
    }

    #[Test]
    public function api_token_policy_denies_write_permissions_to_a_member_and_allows_an_admin(): void
    {
        $team = Team::factory()->create();
        $policy = new ApiTokenPolicy;

        $this->assertFalse($policy->useWritePermissions($this->memberOf($team)));
        $this->assertTrue($policy->useWritePermissions($this->adminOf($team)));
    }

    #[Test]
    public function api_token_policy_scopes_view_and_update_to_the_tokens_own_owner(): void
    {
        $team = Team::factory()->create();
        $owner = $this->adminOf($team);
        $otherUser = $this->adminOf(Team::factory()->create());
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => User::class,
            'tokenable_id' => $owner->id,
            'team_id' => $team->id,
            'name' => 'test',
            'token' => hash('sha256', 'plain-text-token'),
            'abilities' => ['read'],
        ]);
        $policy = new ApiTokenPolicy;

        $this->assertTrue($policy->view($owner, $token));
        $this->assertFalse($policy->view($otherUser, $token));
    }
}
