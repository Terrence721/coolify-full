<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Policies\EnvironmentVariablePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithTeamRoles;
use Tests\TestCase;

/**
 * Regression coverage for a real gap found via an independent /code-review 114 pass (pseudo
 * peer review) on already-merged PR #114: EnvironmentVariablePolicy::canManage() delegates to
 * Gate::allows('manageEnvironment', $resourceable) for whatever model the variable's polymorphic
 * resourceable relation points at. This works for Application/Service/database-engine resources
 * (all have a real manageEnvironment ability), but ServiceApplication/ServiceDatabase - the two
 * sub-resource models inside a service stack, which also have (ServiceApplication) or could gain
 * (ServiceDatabase) their own environment_variables relation - had no manageEnvironment method on
 * their policies at all. Gate::allows() silently returns false for a missing ability rather than
 * throwing, so this would have failed closed with no error for every user, including team owners,
 * the moment any future caller passed one of these two types through envUpdate/envLock/envDestroy.
 * Not reachable via any current controller (only Application/Service/database-engine models are
 * ever passed as $resource today), but ServiceApplication already has a working
 * environment_variables() relation ready for a future caller to use.
 */
class EnvironmentVariablePolicyTest extends TestCase
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

    private function makeServiceDatabase(Team $team): ServiceDatabase
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

        return ServiceDatabase::create([
            'name' => 'db',
            'image' => 'mysql:8',
            'status' => 'running:healthy',
            'service_id' => $service->id,
        ]);
    }

    #[Test]
    public function update_allows_an_admin_when_the_resourceable_is_a_service_application(): void
    {
        $team = Team::factory()->create();
        $serviceApplication = $this->makeServiceApplication($team);
        $env = $serviceApplication->environment_variables()->create([
            'key' => 'FOO',
            'value' => 'bar',
            'resourceable_type' => $serviceApplication->getMorphClass(),
        ]);

        $policy = new EnvironmentVariablePolicy;

        // ServiceApplicationPolicy's methods delegate via the static Gate::allows() facade,
        // which resolves against the currently-authenticated user rather than the $user argument
        // passed to the policy method - actingAs() is required for each case, unlike sibling
        // policies (e.g. NotificationPolicy) that operate on the passed-in $user directly.
        $admin = $this->adminOf($team);
        $this->actingAs($admin);
        $this->assertTrue($policy->update($admin, $env));

        $member = $this->memberOf($team);
        $this->actingAs($member);
        $this->assertFalse($policy->update($member, $env));
    }

    #[Test]
    public function delete_allows_an_admin_when_the_resourceable_is_a_service_database(): void
    {
        // ServiceDatabase has no environment_variables() relation today, so this row can't be
        // created the way the ServiceApplication test above does - constructed directly to prove
        // the policy method itself is correct in advance of that relation ever existing.
        $team = Team::factory()->create();
        $serviceDatabase = $this->makeServiceDatabase($team);
        $env = EnvironmentVariable::create([
            'key' => 'FOO',
            'value' => 'bar',
            'resourceable_type' => $serviceDatabase->getMorphClass(),
            'resourceable_id' => $serviceDatabase->id,
        ]);

        $policy = new EnvironmentVariablePolicy;

        $admin = $this->adminOf($team);
        $this->actingAs($admin);
        $this->assertTrue($policy->delete($admin, $env));

        $member = $this->memberOf($team);
        $this->actingAs($member);
        $this->assertFalse($policy->delete($member, $env));
    }
}
