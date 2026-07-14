<?php

declare(strict_types=1);

use App\Jobs\DeleteResourceJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function svcTabsActingAs(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);

    return $user;
}

function svcTabsMakeService(Team $team): Service
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->destinations()->first();

    return Service::factory()->create([
        'name' => 'test-service',
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function svcTabsParams(Service $service): array
{
    return [
        'project_uuid' => $service->environment->project->uuid,
        'environment_uuid' => $service->environment->uuid,
        'service_uuid' => $service->uuid,
    ];
}

it('renders the tags tab', function () {
    $team = Team::factory()->create();
    svcTabsActingAs($team);
    $service = svcTabsMakeService($team);
    $tag = Tag::create(['name' => 'prod', 'team_id' => $team->id]);
    $service->tags()->attach($tag->id);

    $response = $this->get(route('project.service.tags', svcTabsParams($service)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Service/Configuration')
        ->where('tab', 'tags')
        ->has('tags', 1)
        ->where('tags.0.name', 'prod')
    );
});

it('renders the danger tab', function () {
    $team = Team::factory()->create();
    svcTabsActingAs($team);
    $service = svcTabsMakeService($team);

    $response = $this->get(route('project.service.danger', svcTabsParams($service)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Service/Configuration')
        ->where('tab', 'danger')
        ->where('resourceName', 'test-service')
    );
});

it('renders the webhooks tab with the deploy webhook url', function () {
    $team = Team::factory()->create();
    svcTabsActingAs($team);
    $service = svcTabsMakeService($team);

    $response = $this->get(route('project.service.webhooks', svcTabsParams($service)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Service/Configuration')
        ->where('tab', 'webhooks')
        ->has('deployWebhook')
        ->where('manualWebhooks', null)
    );
});

it('renders the resource operations tab', function () {
    $team = Team::factory()->create();
    svcTabsActingAs($team);
    $service = svcTabsMakeService($team);

    $response = $this->get(route('project.service.resource-operations', svcTabsParams($service)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Service/Configuration')
        ->where('tab', 'resource-operations')
        ->has('servers', 1)
        ->has('projects', 1)
    );
});

it('redirects to the dashboard for a service of another team', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    svcTabsActingAs($team);
    $service = svcTabsMakeService($otherTeam);

    $response = $this->get(route('project.service.tags', svcTabsParams($service)));

    $response->assertRedirect(route('dashboard'));
});

it('attaches and detaches service tags', function () {
    $team = Team::factory()->create();
    svcTabsActingAs($team);
    $service = svcTabsMakeService($team);

    $this->post(route('project.service.tags.store', svcTabsParams($service)), ['tags' => 'web api']);
    expect($service->tags()->pluck('name')->all())->toBe(['web', 'api']);

    $tagId = $service->tags()->first()->id;
    $this->delete(route('project.service.tags.destroy', [...svcTabsParams($service), 'tag_id' => $tagId]));
    expect($service->tags()->count())->toBe(1);
});

it('moves the service to another environment', function () {
    $team = Team::factory()->create();
    svcTabsActingAs($team);
    $service = svcTabsMakeService($team);
    $newEnvironment = Environment::factory()->create([
        'project_id' => $service->environment->project->id,
        'name' => 'staging',
    ]);

    $response = $this->post(route('project.service.move', svcTabsParams($service)), [
        'environment_id' => $newEnvironment->id,
    ]);

    expect($service->fresh()->environment_id)->toBe($newEnvironment->id);
    $response->assertRedirect();
});

it('clones the service to another destination', function () {
    $team = Team::factory()->create();
    svcTabsActingAs($team);
    $service = svcTabsMakeService($team);
    $service->environment_variables()->create([
        'key' => 'CUSTOM_VAR',
        'value' => 'value',
        'resourceable_type' => $service->getMorphClass(),
    ]);
    $secondServer = Server::factory()->create(['team_id' => $team->id]);
    $newDestination = $secondServer->destinations()->first();

    $response = $this->post(route('project.service.clone', svcTabsParams($service)), [
        'destination_id' => $newDestination->id,
    ]);

    $clone = Service::where('destination_id', $newDestination->id)->where('id', '!=', $service->id)->firstOrFail();
    expect($clone->name)->toContain('-clone-');
    expect($clone->server_id)->toBe($secondServer->id);
    expect($clone->environment_variables()->where('key', 'CUSTOM_VAR')->exists())->toBeTrue();
    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain($clone->uuid);
});

it('rejects service deletion with a wrong password', function () {
    $team = Team::factory()->create();
    svcTabsActingAs($team);
    $service = svcTabsMakeService($team);
    Queue::fake();

    $response = $this->delete(route('project.service.destroy', svcTabsParams($service)), [
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHas('error');
    expect(Service::find($service->id))->not->toBeNull();
    Queue::assertNothingPushed();
});

it('deletes the service with the correct password', function () {
    $team = Team::factory()->create();
    svcTabsActingAs($team);
    $service = svcTabsMakeService($team);
    Queue::fake();

    $response = $this->delete(route('project.service.destroy', svcTabsParams($service)), [
        'password' => 'password',
        'delete_volumes' => true,
    ]);

    expect(Service::find($service->id))->toBeNull();
    Queue::assertPushed(DeleteResourceJob::class);
    $response->assertRedirect(route('project.resource.index', [
        'project_uuid' => $service->environment->project->uuid,
        'environment_uuid' => $service->environment->uuid,
    ]));
});
