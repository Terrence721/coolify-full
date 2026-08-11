<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Http\Middleware\CanUpdateResource;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Support\DatabaseEngineRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for a real bug found via an independent /code-review 113 pass
 * (pseudo peer review) on already-merged PR #113: the elseif chain checked service_uuid
 * before stack_service_uuid, but every route that actually supplies stack_service_uuid is
 * nested under service/{service_uuid}, so both params are always present together and the
 * service_uuid branch matched first - permanently making the stack_service_uuid branch
 * (intended to authorize against the specific ServiceApplication/ServiceDatabase) dead code,
 * silently authorizing against the parent Service instead.
 */
class CanUpdateResourceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function authorizes_against_the_specific_service_database_not_just_the_parent_service_when_both_params_are_present(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => 'admin']);
        $this->actingAs($user)->withSession(['currentTeam' => $team]);

        $server = Server::factory()->create(['team_id' => $team->id]);
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
        $destination = $server->standaloneDockers()->first() ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);

        $service = Service::factory()->create([
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);
        $serviceDatabase = ServiceDatabase::create([
            'name' => 'mysql',
            'image' => 'mysql:8',
            'status' => 'running:healthy',
            'service_id' => $service->id,
        ]);

        $route = \Mockery::mock();
        $route->shouldReceive('parameter')->andReturnUsing(function (string $key) use ($service, $serviceDatabase) {
            return match ($key) {
                'service_uuid' => $service->uuid,
                'stack_service_uuid' => $serviceDatabase->uuid,
                default => null,
            };
        });

        $request = Request::create('/');
        $request->setRouteResolver(fn () => $route);

        Gate::shouldReceive('allows')
            ->once()
            ->withArgs(fn (string $ability, $model) => $ability === 'update' && $model instanceof ServiceDatabase && $model->is($serviceDatabase))
            ->andReturn(true);

        $response = (new CanUpdateResource)->handle($request, fn ($req) => response('ok'));

        $this->assertSame('ok', $response->getContent());
    }

    /**
     * Regression coverage for a real bug found in the same /code-review 113 pass: the
     * database_uuid branch hand-listed all 8 standalone database model classes instead of
     * iterating App\Support\DatabaseEngineRegistry::modelClasses(), the single source of truth
     * written specifically to prevent this pattern - a 9th engine added later would silently be
     * missing from this authorization check. Sourcing the dataset itself from the registry (not
     * a hardcoded list) means this test automatically covers a future engine the moment it's
     * registered there, with no edit needed here.
     */
    #[Test]
    public function authorizes_against_every_registered_database_engine(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => 'admin']);
        $this->actingAs($user)->withSession(['currentTeam' => $team]);

        $server = Server::factory()->create(['team_id' => $team->id]);
        $destination = $server->standaloneDockers()->first() ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);

        foreach (DatabaseEngineRegistry::all() as $engine) {
            $database = $engine->modelClass::factory()->create([
                'environment_id' => $environment->id,
                'destination_id' => $destination->id,
                'destination_type' => $destination->getMorphClass(),
            ]);

            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->andReturnUsing(fn (string $key) => $key === 'database_uuid' ? $database->uuid : null);

            $request = Request::create('/');
            $request->setRouteResolver(fn () => $route);

            $response = (new CanUpdateResource)->handle($request, fn ($req) => response('ok'));

            $this->assertSame('ok', $response->getContent(), "database_uuid branch failed to authorize a real admin for engine [{$engine->type}]");
        }
    }
}
