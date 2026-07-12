<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

/**
 * Renamed per this migration's established convention to avoid Pest's global-function-name
 * collision across test files (all Feature test files share one PHP process).
 */
function createServiceResourceFixture(Team $team): array
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->first();
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    return [$project, $environment, $server, $service];
}

function serviceResourceParams(Project $project, Environment $environment, Service $service, string $stackServiceUuid): array
{
    return [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'service_uuid' => $service->uuid,
        'stack_service_uuid' => $stackServiceUuid,
    ];
}

it('renders the application general tab', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $service] = createServiceResourceFixture($team);
    $application = $service->applications()->create(['name' => 'app1', 'image' => 'nginx:latest']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.service.index', serviceResourceParams($project, $environment, $service, $application->uuid)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Service/Resource')
        ->where('resourceType', 'application')
        ->where('tab', 'general')
        ->where('application.name', 'app1')
    );
});

it('renders the application advanced tab', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $service] = createServiceResourceFixture($team);
    $application = $service->applications()->create(['name' => 'app1', 'image' => 'nginx:latest']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.service.index.advanced', serviceResourceParams($project, $environment, $service, $application->uuid)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Service/Resource')
        ->where('tab', 'advanced')
    );
});

it('renders the database general tab', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $service] = createServiceResourceFixture($team);
    $database = $service->databases()->create(['name' => 'db1', 'image' => 'postgres:15']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.service.index', serviceResourceParams($project, $environment, $service, $database->uuid)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Service/Resource')
        ->where('resourceType', 'database')
        ->where('database.name', 'db1')
        ->where('database.isImportSupported', true)
    );
});

it('redirects to the service configuration page for a nonexistent stack resource', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $service] = createServiceResourceFixture($team);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.service.index', serviceResourceParams($project, $environment, $service, 'does-not-exist')));

    $response->assertRedirect(route('project.service.configuration', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'service_uuid' => $service->uuid,
    ]));
});

it('updates an application and saves without a domain', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $service] = createServiceResourceFixture($team);
    $application = $service->applications()->create(['name' => 'app1', 'image' => 'nginx:latest']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.service.application.update', serviceResourceParams($project, $environment, $service, $application->uuid)), [
            'human_name' => 'My App',
            'description' => 'desc',
            'fqdn' => '',
            'image' => 'nginx:1.27',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Service saved.');
    expect($application->refresh()->human_name)->toBe('My App');
    expect($application->refresh()->image)->toBe('nginx:1.27');
});

it('flags a domain conflict instead of saving the application', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $service] = createServiceResourceFixture($team);
    $application = $service->applications()->create(['name' => 'app1', 'image' => 'nginx:latest']);
    Application::factory()->create([
        'environment_id' => $environment->id,
        'fqdn' => 'https://taken.example.com',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.service.application.update', serviceResourceParams($project, $environment, $service, $application->uuid)), [
            'human_name' => 'My App',
            'fqdn' => 'https://taken.example.com',
            'image' => 'nginx:latest',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('showDomainConflictModal', true);
    $response->assertSessionHas('domainConflicts');
    expect($application->refresh()->fqdn)->not->toBe('https://taken.example.com');
});

it('saves a conflicting application domain when force_save_domains is set', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $service] = createServiceResourceFixture($team);
    $application = $service->applications()->create(['name' => 'app1', 'image' => 'nginx:latest']);
    Application::factory()->create([
        'environment_id' => $environment->id,
        'fqdn' => 'https://taken.example.com',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.service.application.update', serviceResourceParams($project, $environment, $service, $application->uuid)), [
            'human_name' => 'My App',
            'fqdn' => 'https://taken.example.com',
            'image' => 'nginx:latest',
            'force_save_domains' => true,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Service saved.');
    expect($application->refresh()->fqdn)->toBe('https://taken.example.com');
});

it('toggles application advanced settings', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $service] = createServiceResourceFixture($team);
    $application = $service->applications()->create(['name' => 'app1', 'image' => 'nginx:latest', 'is_gzip_enabled' => true]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.service.application.update-advanced', serviceResourceParams($project, $environment, $service, $application->uuid)), [
            'is_gzip_enabled' => false,
            'is_stripprefix_enabled' => true,
            'exclude_from_status' => true,
            'is_log_drain_enabled' => false,
        ]);

    $response->assertRedirect();
    expect($application->refresh())
        ->is_gzip_enabled->toBeFalsy()
        ->is_stripprefix_enabled->toBeTruthy()
        ->exclude_from_status->toBeTruthy();
});

it('blocks enabling application log drain when the server does not have it enabled', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $service] = createServiceResourceFixture($team);
    $application = $service->applications()->create(['name' => 'app1', 'image' => 'nginx:latest']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.service.application.update-advanced', serviceResourceParams($project, $environment, $service, $application->uuid)), [
            'is_gzip_enabled' => true,
            'is_stripprefix_enabled' => true,
            'exclude_from_status' => false,
            'is_log_drain_enabled' => true,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Log drain is not enabled on the server. Please enable it first.');
    expect($application->refresh()->is_log_drain_enabled)->toBeFalsy();
});

it('converts an application to a database', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $service] = createServiceResourceFixture($team);
    $application = $service->applications()->create(['name' => 'app1', 'image' => 'nginx:latest']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.service.application.convert', serviceResourceParams($project, $environment, $service, $application->uuid)));

    $response->assertRedirect(route('project.service.configuration', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'service_uuid' => $service->uuid,
    ]));
    expect($service->applications()->count())->toBe(0);
    expect($service->databases()->where('name', 'app1')->exists())->toBeTrue();
});

it('deletes an application', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $service] = createServiceResourceFixture($team);
    $application = $service->applications()->create(['name' => 'app1', 'image' => 'nginx:latest']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('project.service.application.delete', serviceResourceParams($project, $environment, $service, $application->uuid)));

    $response->assertRedirect();
    expect($service->applications()->count())->toBe(0);
});

it('updates a database and saves', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $service] = createServiceResourceFixture($team);
    $database = $service->databases()->create(['name' => 'db1', 'image' => 'postgres:15']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.service.database.update', serviceResourceParams($project, $environment, $service, $database->uuid)), [
            'human_name' => 'My DB',
            'description' => 'desc',
            'image' => 'postgres:16',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Database saved.');
    expect($database->refresh()->human_name)->toBe('My DB');
});

it('refuses to make a stopped database public', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $service] = createServiceResourceFixture($team);
    $database = $service->databases()->create(['name' => 'db1', 'image' => 'postgres:15', 'status' => 'exited']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.service.database.update-public', serviceResourceParams($project, $environment, $service, $database->uuid)), [
            'is_public' => true,
            'public_port' => 5432,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Database must be started to be publicly accessible.');
    expect($database->refresh()->is_public)->toBeFalsy();
});

it('converts a database to an application', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $service] = createServiceResourceFixture($team);
    $database = $service->databases()->create(['name' => 'db1', 'image' => 'postgres:15']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.service.database.convert', serviceResourceParams($project, $environment, $service, $database->uuid)));

    $response->assertRedirect(route('project.service.configuration', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'service_uuid' => $service->uuid,
    ]));
    expect($service->databases()->count())->toBe(0);
    expect($service->applications()->where('name', 'db1')->exists())->toBeTrue();
});

it('deletes a database', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $service] = createServiceResourceFixture($team);
    $database = $service->databases()->create(['name' => 'db1', 'image' => 'postgres:15']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('project.service.database.delete', serviceResourceParams($project, $environment, $service, $database->uuid)));

    $response->assertRedirect();
    expect($service->databases()->count())->toBe(0);
});

it('returns empty proxy logs for a non-functional server without touching SSH', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    [$project, $environment, , $service] = createServiceResourceFixture($team);
    $database = $service->databases()->create(['name' => 'db1', 'image' => 'postgres:15']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson(route('project.service.database.proxy-logs', serviceResourceParams($project, $environment, $service, $database->uuid)));

    $response->assertOk();
    $response->assertJson(['logLines' => []]);
});

it('404s for a service another team owns', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    [$project, $environment, , $service] = createServiceResourceFixture($otherTeam);
    $application = $service->applications()->create(['name' => 'app1', 'image' => 'nginx:latest']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.service.index', serviceResourceParams($project, $environment, $service, $application->uuid)));

    $response->assertRedirect(route('dashboard'));
});
