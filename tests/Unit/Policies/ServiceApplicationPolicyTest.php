<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Policies\ServiceApplicationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithTeamRoles;
use Tests\TestCase;

/**
 * Regression coverage for a real gap found via an independent /code-review 181 pass (pseudo peer
 * review) on already-merged PR #181: every method here delegated via the static Gate::allows()
 * facade, which resolves against the currently-authenticated user rather than the $user argument
 * Laravel passes to a policy method - the same silent-wrong-actor trap
 * EnvironmentVariablePolicy::canManage() exists to avoid by using Gate::forUser($user) instead.
 */
class ServiceApplicationPolicyTest extends TestCase
{
    use InteractsWithTeamRoles, RefreshDatabase;

    private function makeServiceApplication(Team $team): ServiceApplication
    {
        $server = Server::factory()->create(['team_id' => $team->id]);
        $destination = $server->standaloneDockers()->first() ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = $project->environments()->first();
        $service = Service::factory()->create([
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);

        return ServiceApplication::create([
            'name' => 'app',
            'image' => 'nginx:alpine',
            'status' => 'running:healthy',
            'service_id' => $service->id,
        ]);
    }

    #[Test]
    public function delegate_abilities_authorize_the_passed_user_not_the_current_session(): void
    {
        $team = Team::factory()->create();
        $serviceApplication = $this->makeServiceApplication($team);
        $admin = $this->adminOf($team);
        $member = $this->memberOf($team);

        $policy = new ServiceApplicationPolicy;

        // Session is the admin, but the ability is asked about $member - must resolve to
        // $member's lack of access, not silently inherit the admin session's privileges.
        $this->actingAs($admin);
        $this->assertFalse($policy->update($member, $serviceApplication));
        $this->assertFalse($policy->delete($member, $serviceApplication));
        $this->assertFalse($policy->forceDelete($member, $serviceApplication));
        $this->assertFalse($policy->manageEnvironment($member, $serviceApplication));

        // Session is the member, but the ability is asked about $admin - must resolve to
        // $admin's real access, not silently deny based on the member session.
        $this->actingAs($member);
        $this->assertTrue($policy->update($admin, $serviceApplication));
        $this->assertTrue($policy->delete($admin, $serviceApplication));
        $this->assertTrue($policy->forceDelete($admin, $serviceApplication));
        $this->assertTrue($policy->manageEnvironment($admin, $serviceApplication));
    }
}
