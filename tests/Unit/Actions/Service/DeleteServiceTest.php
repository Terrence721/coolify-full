<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Service;

use App\Actions\Service\DeleteService;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Fakes\RemoteProcessFake;
use Tests\TestCase;

require_once __DIR__.'/../../../Support/Fakes/service_action_remote_process_overrides.php';

/**
 * EnvironmentVariable's relation to Service/ServiceApplication is a polymorphic MorphMany with
 * no DB foreign key, so nothing cascades it when the owning row is deleted (ServiceDatabase has
 * no environment_variables relation at all - only Service and ServiceApplication do). These
 * tests lock in that DeleteService cleans up both owning models' own environment variables
 * regardless of $deleteVolumes or server reachability, both of which used to gate the Service's
 * own cleanup (and ServiceApplication never cleaned up its own at all).
 */
class DeleteServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RemoteProcessFake::reset();
    }

    private function createServiceWithTeamChain(Team $team, array $attributes = []): Service
    {
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);

        return Service::factory()->create([
            ...$attributes,
            'environment_id' => $environment->id,
        ]);
    }

    #[Test]
    public function it_deletes_the_services_own_environment_variables_even_when_delete_volumes_is_false()
    {
        $service = $this->createServiceWithTeamChain(Team::factory()->create());
        EnvironmentVariable::create([
            'key' => 'DB_PASSWORD',
            'value' => 'super-secret',
            'resourceable_type' => Service::class,
            'resourceable_id' => $service->id,
        ]);

        DeleteService::run($service, deleteVolumes: false, deleteConnectedNetworks: false, deleteConfigurations: false, dockerCleanup: false);

        $this->assertSame(0, EnvironmentVariable::where('resourceable_type', Service::class)->where('resourceable_id', $service->id)->count());
    }

    #[Test]
    public function it_deletes_the_services_own_environment_variables_even_when_the_server_is_not_functional()
    {
        $team = Team::factory()->create();
        $server = Server::factory()->create(['team_id' => $team->id, 'ip' => '1.2.3.4']);
        $service = $this->createServiceWithTeamChain($team, ['server_id' => $server->id]);
        EnvironmentVariable::create([
            'key' => 'API_KEY',
            'value' => 'sk-secret',
            'resourceable_type' => Service::class,
            'resourceable_id' => $service->id,
        ]);

        // ip '1.2.3.4' makes Server::isFunctional() false regardless of settings - the exact
        // path that used to skip environment_variables()->delete() entirely.
        DeleteService::run($service, deleteVolumes: true, deleteConnectedNetworks: false, deleteConfigurations: false, dockerCleanup: false);

        $this->assertSame(0, EnvironmentVariable::where('resourceable_type', Service::class)->where('resourceable_id', $service->id)->count());
    }

    #[Test]
    public function it_deletes_a_service_applications_own_environment_variables_when_the_service_is_deleted()
    {
        $service = $this->createServiceWithTeamChain(Team::factory()->create());
        $application = ServiceApplication::factory()->create(['service_id' => $service->id]);
        EnvironmentVariable::create([
            'key' => 'APP_SECRET',
            'value' => 'app-secret-value',
            'resourceable_type' => ServiceApplication::class,
            'resourceable_id' => $application->id,
        ]);

        DeleteService::run($service, deleteVolumes: false, deleteConnectedNetworks: false, deleteConfigurations: false, dockerCleanup: false);

        $this->assertSame(
            0,
            EnvironmentVariable::where('resourceable_type', ServiceApplication::class)->where('resourceable_id', $application->id)->count(),
        );
    }

    #[Test]
    public function it_still_deletes_the_service_itself()
    {
        $service = $this->createServiceWithTeamChain(Team::factory()->create());
        $serviceId = $service->id;

        DeleteService::run($service, deleteVolumes: false, deleteConnectedNetworks: false, deleteConfigurations: false, dockerCleanup: false);

        $this->assertNull(Service::find($serviceId));
    }
}
