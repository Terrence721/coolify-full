<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

/**
 * Renamed per this migration's established convention to avoid Pest's global-function-name
 * collision across test files (all Feature test files share one PHP process).
 */
function createResourceTestChain(Team $team): array
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = $server->standaloneDockers()->first();

    return [$project, $environment, $server, $destination];
}

function actingAsTeamMember(Team $team): void
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);
}

it('renders the type-picker step when no type is chosen yet', function () {
    $team = Team::factory()->create();
    actingAsTeamMember($team);
    [$project, $environment] = createResourceTestChain($team);

    $response = $this->get(route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
    ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Resource/Create')
        ->where('step', 'type')
        ->has('services')
        ->has('gitBasedApplications')
        ->has('dockerBasedApplications')
        ->has('databases')
    );
});

it('redirects to the dashboard for a nonexistent project', function () {
    $team = Team::factory()->create();
    actingAsTeamMember($team);

    $response = $this->get(route('project.resource.create', [
        'project_uuid' => 'does-not-exist',
        'environment_uuid' => 'does-not-exist',
    ]));

    $response->assertRedirect(route('dashboard'));
});

it('redirects to the dashboard for a nonexistent environment', function () {
    $team = Team::factory()->create();
    actingAsTeamMember($team);
    $project = Project::factory()->create(['team_id' => $team->id]);

    $response = $this->get(route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => 'does-not-exist',
    ]));

    $response->assertRedirect(route('dashboard'));
});

it('creates a redis database directly when server and destination are unambiguous', function () {
    $team = Team::factory()->create();
    actingAsTeamMember($team);
    [$project, $environment] = createResourceTestChain($team);

    $response = $this->get(route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'type' => 'redis',
    ]));

    $database = StandaloneRedis::where('environment_id', $environment->id)->firstOrFail();
    $response->assertRedirect(route('project.database.configuration', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'database_uuid' => $database->uuid,
    ]));
});

it('renders the postgresql version step before creating a postgresql database', function () {
    $team = Team::factory()->create();
    actingAsTeamMember($team);
    [$project, $environment, $server, $destination] = createResourceTestChain($team);

    $response = $this->get(route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'type' => 'postgresql',
        'server_id' => $server->id,
        'destination' => $destination->uuid,
    ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Resource/Create')
        ->where('step', 'select-postgresql-type')
        ->has('postgresqlVersions', 7)
    );
    expect(StandalonePostgresql::where('environment_id', $environment->id)->exists())->toBeFalse();
});

it('creates a postgresql database once a version is chosen', function () {
    $team = Team::factory()->create();
    actingAsTeamMember($team);
    [$project, $environment, $server, $destination] = createResourceTestChain($team);

    $response = $this->get(route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'type' => 'postgresql',
        'server_id' => $server->id,
        'destination' => $destination->uuid,
        'database_image' => 'postgres:17-alpine',
    ]));

    $database = StandalonePostgresql::where('environment_id', $environment->id)->firstOrFail();
    expect($database->image)->toBe('postgres:17-alpine');
    $response->assertRedirect(route('project.database.configuration', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'database_uuid' => $database->uuid,
    ]));
});

it('renders the servers step when multiple usable servers exist', function () {
    $team = Team::factory()->create();
    actingAsTeamMember($team);
    [$project, $environment] = createResourceTestChain($team);
    $secondServer = Server::factory()->create(['team_id' => $team->id]);
    $secondServer->settings()->update(['is_reachable' => true, 'is_usable' => true]);

    $response = $this->get(route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'type' => 'redis',
    ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Resource/Create')
        ->where('step', 'servers')
        ->has('servers', 2)
    );
});

it('renders the destinations step when multiple destinations exist on the chosen server', function () {
    $team = Team::factory()->create();
    actingAsTeamMember($team);
    [$project, $environment, $server] = createResourceTestChain($team);
    StandaloneDocker::create([
        'name' => 'second-network',
        'network' => 'second-network',
        'server_id' => $server->id,
    ]);

    $response = $this->get(route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'type' => 'redis',
        'server_id' => $server->id,
    ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Resource/Create')
        ->where('step', 'destinations')
        ->has('destinations', 2)
    );
});

it('creates a one-click service from a real template', function () {
    $team = Team::factory()->create();
    actingAsTeamMember($team);
    [$project, $environment, $server, $destination] = createResourceTestChain($team);

    $response = $this->get(route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'type' => 'one-click-service-cloudflare-ddns',
        'server_id' => $server->id,
        'destination' => $destination->uuid,
    ]));

    $service = Service::where('environment_id', $environment->id)->firstOrFail();
    expect($service->service_type)->toBe('cloudflare-ddns');
    $response->assertRedirect(route('project.service.configuration', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'service_uuid' => $service->uuid,
    ]));
});

it('creates a one-click service whose template declares SERVICE_FQDN_/SERVICE_URL_ magic env vars, including a port-suffixed variant, without crashing under the test database', function () {
    // grafana's compose declares both SERVICE_URL_GRAFANA_3000 (port-suffixed) and
    // SERVICE_FQDN_GRAFANA/SERVICE_URL_GRAFANA (bare) — this exercises serviceParser()'s
    // port-suffix-detection query, previously PostgreSQL-only (`whereRaw('key ~ ?', ...)`)
    // and unrunnable against the SQLite test connection. See todo.md/migration doc.
    // Queue::fake() sidesteps a second, unrelated, already-accepted gap: grafana's compose
    // also declares a persistent volume, which makes serviceParser() dispatch
    // ServerFilesFromServerJob — a real SSH call this test environment can't satisfy.
    Queue::fake();

    $team = Team::factory()->create();
    actingAsTeamMember($team);
    [$project, $environment, $server, $destination] = createResourceTestChain($team);

    $response = $this->get(route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'type' => 'one-click-service-grafana',
        'server_id' => $server->id,
        'destination' => $destination->uuid,
    ]));

    $service = Service::where('environment_id', $environment->id)->firstOrFail();
    expect($service->service_type)->toBe('grafana');
    $response->assertRedirect(route('project.service.configuration', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'service_uuid' => $service->uuid,
    ]));

    $keys = $service->environment_variables()->pluck('key')->all();
    expect($keys)->toContain('SERVICE_FQDN_GRAFANA', 'SERVICE_URL_GRAFANA', 'SERVICE_URL_GRAFANA_3000');
});

it('redirects back to the wizard for an unknown one-click service template', function () {
    $team = Team::factory()->create();
    actingAsTeamMember($team);
    [$project, $environment, $server, $destination] = createResourceTestChain($team);

    $response = $this->get(route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'type' => 'one-click-service-does-not-exist',
        'server_id' => $server->id,
        'destination' => $destination->uuid,
    ]));

    $response->assertRedirect(route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
    ]));
});

it('redirects github-based types to the dedicated git creation route', function (string $type) {
    $team = Team::factory()->create();
    actingAsTeamMember($team);
    [$project, $environment, $server, $destination] = createResourceTestChain($team);

    $response = $this->get(route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'type' => $type,
        'server_id' => $server->id,
        'destination' => $destination->uuid,
    ]));

    $response->assertRedirect(route('project.resource.create.git', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'type' => $type,
        'destination' => $destination->uuid,
        'server_id' => $server->id,
    ]));
})->with(['public', 'private-gh-app', 'private-deploy-key']);

it('renders the dockerfile creation form', function () {
    $team = Team::factory()->create();
    actingAsTeamMember($team);
    [$project, $environment, $server, $destination] = createResourceTestChain($team);

    $response = $this->get(route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'type' => 'dockerfile',
        'server_id' => $server->id,
        'destination' => $destination->uuid,
    ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('Project/New/SimpleDockerfile')->has('submitUrl'));
});

it('creates an application from a submitted dockerfile', function () {
    $team = Team::factory()->create();
    actingAsTeamMember($team);
    [$project, $environment, , $destination] = createResourceTestChain($team);

    $response = $this->post(route('project.resource.create.dockerfile', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'destination' => $destination->uuid,
    ]), [
        'dockerfile' => "FROM nginx\nEXPOSE 8080\n",
    ]);

    $application = Application::where('environment_id', $environment->id)->firstOrFail();
    expect($application->build_pack)->toBe('dockerfile');
    expect($application->ports_exposes)->toBe('8080');
    $response->assertRedirect(route('project.application.configuration', [
        'application_uuid' => $application->uuid,
        'environment_uuid' => $environment->uuid,
        'project_uuid' => $project->uuid,
    ]));
});

it('renders the docker image creation form', function () {
    $team = Team::factory()->create();
    actingAsTeamMember($team);
    [$project, $environment, $server, $destination] = createResourceTestChain($team);

    $response = $this->get(route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'type' => 'docker-image',
        'server_id' => $server->id,
        'destination' => $destination->uuid,
    ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('Project/New/DockerImage')->has('submitUrl'));
});

it('creates an application from a submitted docker image', function () {
    $team = Team::factory()->create();
    actingAsTeamMember($team);
    [$project, $environment, , $destination] = createResourceTestChain($team);

    $response = $this->post(route('project.resource.create.docker-image', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'destination' => $destination->uuid,
    ]), [
        'imageName' => 'nginx',
        'imageTag' => 'alpine',
    ]);

    $application = Application::where('environment_id', $environment->id)->firstOrFail();
    expect($application->build_pack)->toBe('dockerimage');
    expect($application->docker_registry_image_name)->toBe('nginx');
    expect($application->docker_registry_image_tag)->toBe('alpine');
    $response->assertRedirect(route('project.application.configuration', [
        'application_uuid' => $application->uuid,
        'environment_uuid' => $environment->uuid,
        'project_uuid' => $project->uuid,
    ]));
});

it('rejects a docker image submission with both a tag and a sha256 digest', function () {
    $team = Team::factory()->create();
    actingAsTeamMember($team);
    [$project, $environment, , $destination] = createResourceTestChain($team);

    $response = $this->from(route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'type' => 'docker-image',
        'destination' => $destination->uuid,
    ]))->post(route('project.resource.create.docker-image', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'destination' => $destination->uuid,
    ]), [
        'imageName' => 'nginx',
        'imageTag' => 'alpine',
        'imageSha256' => str_repeat('a', 64),
    ]);

    $response->assertSessionHasErrors(['imageTag', 'imageSha256']);
    expect(Application::where('environment_id', $environment->id)->exists())->toBeFalse();
});

it('renders the docker compose creation form', function () {
    $team = Team::factory()->create();
    actingAsTeamMember($team);
    [$project, $environment, $server, $destination] = createResourceTestChain($team);

    $response = $this->get(route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'type' => 'docker-compose-empty',
        'server_id' => $server->id,
        'destination' => $destination->uuid,
    ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('Project/New/DockerCompose')->has('submitUrl'));
});

it('creates a service from submitted docker compose yaml', function () {
    $team = Team::factory()->create();
    actingAsTeamMember($team);
    [$project, $environment, , $destination] = createResourceTestChain($team);

    $compose = <<<'YAML'
services:
  app:
    image: nginx:alpine
    ports:
      - 8080:80
YAML;

    $response = $this->post(route('project.resource.create.docker-compose', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'destination' => $destination->uuid,
    ]), [
        'dockerComposeRaw' => $compose,
    ]);

    $service = Service::where('environment_id', $environment->id)->firstOrFail();
    expect($service->server_id)->toBe($destination->server_id);
    $response->assertRedirect(route('project.service.configuration', [
        'service_uuid' => $service->uuid,
        'environment_uuid' => $environment->uuid,
        'project_uuid' => $project->uuid,
    ]));
});

it('404s the docker-based submit endpoints when the destination no longer exists', function () {
    $team = Team::factory()->create();
    actingAsTeamMember($team);
    [$project, $environment] = createResourceTestChain($team);

    $response = $this->post(route('project.resource.create.dockerfile', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'destination' => 'does-not-exist',
    ]), [
        'dockerfile' => "FROM nginx\n",
    ]);

    $response->assertStatus(404);
});
