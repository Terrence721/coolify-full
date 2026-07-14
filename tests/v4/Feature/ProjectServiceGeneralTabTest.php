<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

const SVC_GENERAL_COMPOSE = <<<'YAML'
services:
  app:
    image: nginx
    environment:
      - APP_ENV=production
YAML;

function svcGeneralActingAs(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);

    return $user;
}

function svcGeneralMakeService(Team $team): Service
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->destinations()->first();

    return Service::factory()->create([
        'name' => 'test-service',
        'docker_compose_raw' => SVC_GENERAL_COMPOSE,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function svcGeneralParams(Service $service): array
{
    return [
        'project_uuid' => $service->environment->project->uuid,
        'environment_uuid' => $service->environment->uuid,
        'service_uuid' => $service->uuid,
    ];
}

it('renders the general tab with stack form, resource cards, and details', function () {
    $team = Team::factory()->create();
    svcGeneralActingAs($team);
    $service = svcGeneralMakeService($team);
    $service->applications()->create([
        'name' => 'app',
        'image' => 'nginx',
        'fqdn' => 'https://app.example.com',
        'status' => 'running:healthy',
    ]);

    $response = $this->get(route('project.service.configuration', svcGeneralParams($service)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Service/Configuration')
        ->where('tab', 'configuration')
        ->where('stackForm.name', 'test-service')
        ->where('stackForm.dockerComposeRaw', SVC_GENERAL_COMPOSE)
        ->has('resources', 1)
        ->where('resources.0.isApplication', true)
        ->where('resources.0.fqdn', 'https://app.example.com')
        ->where('resourceDetails.resource.uuid', $service->uuid)
        ->has('resourceDetails.stackApplications', 1)
        ->has('generalUrls.update')
    );
});

it('saves the stack form and re-parses the compose file', function () {
    $team = Team::factory()->create();
    svcGeneralActingAs($team);
    $service = svcGeneralMakeService($team);

    $response = $this->patch(route('project.service.general.update', svcGeneralParams($service)), [
        'name' => 'renamed-service',
        'description' => 'a description',
        'dockerComposeRaw' => SVC_GENERAL_COMPOSE,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Service saved.');
    $service->refresh();
    expect($service->name)->toBe('renamed-service')
        ->and($service->description)->toBe('a description')
        ->and($service->applications()->count())->toBe(1)
        ->and($service->applications()->first()->name)->toBe('app');
});

it('rejects a compose file with command injection attempts', function () {
    $team = Team::factory()->create();
    svcGeneralActingAs($team);
    $service = svcGeneralMakeService($team);

    $response = $this->patch(route('project.service.general.update', svcGeneralParams($service)), [
        'name' => 'renamed-service',
        'dockerComposeRaw' => "services:\n  app:\n    image: nginx\n    volumes:\n      - \$(rm -rf /):/data",
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect($service->refresh()->name)->toBe('test-service');
});

it('saves the instant-save settings checkboxes', function () {
    $team = Team::factory()->create();
    svcGeneralActingAs($team);
    $service = svcGeneralMakeService($team);

    $this->patch(route('project.service.general.settings', svcGeneralParams($service)), [
        'connectToDockerNetwork' => true,
    ])->assertSessionHas('success', 'Service settings saved.');
    expect($service->refresh()->connect_to_docker_network)->toBeTrue();

    $this->patch(route('project.service.general.settings', svcGeneralParams($service)), [
        'isContainerLabelEscapeEnabled' => false,
    ]);
    $service->refresh();
    expect($service->is_container_label_escape_enabled)->toBeFalse()
        ->and($service->connect_to_docker_network)->toBeTrue();
});

it('updates a child domain, normalizing and deduplicating', function () {
    $team = Team::factory()->create();
    svcGeneralActingAs($team);
    $service = svcGeneralMakeService($team);
    $application = $service->applications()->create(['name' => 'app', 'image' => 'nginx']);

    $response = $this->patch(route('project.service.child.domain', [...svcGeneralParams($service), 'application_id' => $application->id]), [
        'fqdn' => 'https://APP.example.com, https://app.example.com,',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Service saved.');
    expect($application->refresh()->fqdn)->toBe('https://app.example.com');
});

it('flags a domain conflict instead of saving the child domain', function () {
    $team = Team::factory()->create();
    svcGeneralActingAs($team);
    $service = svcGeneralMakeService($team);
    $application = $service->applications()->create(['name' => 'app', 'image' => 'nginx']);
    Application::factory()->create([
        'environment_id' => $service->environment_id,
        'fqdn' => 'https://taken.example.com',
    ]);

    $response = $this->patch(route('project.service.child.domain', [...svcGeneralParams($service), 'application_id' => $application->id]), [
        'fqdn' => 'https://taken.example.com',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('showDomainConflictModal', true);
    expect($application->refresh()->fqdn)->toBeNull();
});

it('saves a conflicting child domain when force_save_domains is set', function () {
    $team = Team::factory()->create();
    svcGeneralActingAs($team);
    $service = svcGeneralMakeService($team);
    $application = $service->applications()->create(['name' => 'app', 'image' => 'nginx']);
    Application::factory()->create([
        'environment_id' => $service->environment_id,
        'fqdn' => 'https://taken.example.com',
    ]);

    $response = $this->patch(route('project.service.child.domain', [...svcGeneralParams($service), 'application_id' => $application->id]), [
        'fqdn' => 'https://taken.example.com',
        'force_save_domains' => true,
    ]);

    $response->assertSessionHas('success', 'Service saved.');
    expect($application->refresh()->fqdn)->toBe('https://taken.example.com');
});

it('returns 404 when restarting an unknown stack child', function () {
    $team = Team::factory()->create();
    svcGeneralActingAs($team);
    $service = svcGeneralMakeService($team);

    $this->post(route('project.service.child.restart', [...svcGeneralParams($service), 'child_uuid' => 'nope']))
        ->assertNotFound();
});

it('redirects cross-team visitors to the dashboard', function () {
    $teamA = Team::factory()->create();
    $service = svcGeneralMakeService($teamA);
    svcGeneralActingAs(Team::factory()->create());

    $this->get(route('project.service.configuration', svcGeneralParams($service)))
        ->assertRedirect(route('dashboard'));
});
