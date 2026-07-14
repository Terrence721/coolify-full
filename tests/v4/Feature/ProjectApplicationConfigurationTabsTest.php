<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function appTabsActingAs(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);

    return $user;
}

function appTabsMakeApplication(Team $team, array $attrs = []): Application
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();

    return Application::factory()->create([
        'name' => 'my-app',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        ...$attrs,
    ]);
}

function appTabsParams(Application $application): array
{
    return [
        'project_uuid' => $application->environment->project->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
    ];
}

it('renders the tags tab', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);
    $tag = Tag::create(['name' => 'prod', 'team_id' => $team->id]);
    $application->tags()->attach($tag->id);

    $response = $this->get(route('project.application.tags', appTabsParams($application)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Application/Configuration')
        ->where('tab', 'tags')
        ->has('tags', 1)
        ->where('tags.0.name', 'prod')
    );
});

it('adds and removes a tag', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);

    $this->post(route('project.application.tags.store', appTabsParams($application)), [
        'tags' => 'staging',
    ])->assertSessionHas('success', 'Tags added.');
    $tag = Tag::where('name', 'staging')->firstOrFail();
    expect($application->tags()->where('tags.id', $tag->id)->exists())->toBeTrue();

    $this->delete(route('project.application.tags.destroy', [...appTabsParams($application), 'tag_id' => $tag->id]))
        ->assertSessionHas('success', 'Tag deleted.');
    expect($application->tags()->where('tags.id', $tag->id)->exists())->toBeFalse();
});

it('renders the danger tab and deletes with a correct password', function () {
    $team = Team::factory()->create();
    $user = appTabsActingAs($team);
    $application = appTabsMakeApplication($team);

    $this->get(route('project.application.danger', appTabsParams($application)))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Project/Application/Configuration')
            ->where('tab', 'danger')
            ->where('resourceName', 'my-app')
            ->where('canDelete', true)
        );

    $response = $this->delete(route('project.application.destroy', appTabsParams($application)), [
        'password' => 'password',
    ]);

    $response->assertRedirect();
    expect(Application::find($application->id))->toBeNull();
});

it('rejects deletion with the wrong password', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);

    $this->delete(route('project.application.destroy', appTabsParams($application)), [
        'password' => 'not-the-password',
    ])->assertSessionHas('error', 'The provided password is incorrect.');

    expect(Application::find($application->id))->not->toBeNull();
});

it('renders and updates the resource limits tab', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);

    $this->get(route('project.application.resource-limits', appTabsParams($application)))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Project/Application/Configuration')
            ->where('tab', 'resource-limits')
            ->has('limits')
            ->has('limitsUpdateUrl')
        );

    $response = $this->patch(route('project.application.resource-limits.update', appTabsParams($application)), [
        'limitsMemory' => '512m',
        'limitsCpus' => '1.5',
    ]);

    $response->assertSessionHas('success', 'Resource limits updated.');
    expect($application->refresh()->limits_memory)->toBe('512m')
        ->and($application->limits_cpus)->toBe('1.5');
});

it('renders the resource operations tab', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);

    $response = $this->get(route('project.application.resource-operations', appTabsParams($application)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Application/Configuration')
        ->where('tab', 'resource-operations')
        ->has('servers')
        ->has('projects')
        ->has('operationUrls.clone')
        ->has('operationUrls.move')
    );
});

it('moves an application to a different environment', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);
    $newEnvironment = $application->environment->project->environments()->create(['name' => 'staging']);

    $response = $this->post(route('project.application.move', appTabsParams($application)), [
        'environment_id' => $newEnvironment->id,
    ]);

    $response->assertRedirect();
    expect($application->refresh()->environment_id)->toBe($newEnvironment->id);
});

it('clones an application via clone_application()', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);
    $tag = Tag::create(['name' => 'prod', 'team_id' => $team->id]);
    $application->tags()->attach($tag->id);
    $newServer = Server::factory()->create(['team_id' => $team->id]);
    $newDestination = $newServer->standaloneDockers()->first();

    $response = $this->post(route('project.application.clone', appTabsParams($application)), [
        'destination_id' => $newDestination->id,
    ]);

    $response->assertRedirect();
    expect(Application::where('id', '!=', $application->id)->where('destination_id', $newDestination->id)->count())->toBe(1);
    $clone = Application::where('id', '!=', $application->id)->where('destination_id', $newDestination->id)->first();
    expect($clone->tags()->where('tags.id', $tag->id)->exists())->toBeTrue()
        ->and($clone->name)->toContain('clone-of');
});

it('rejects cloning to a destination not owned by the team', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDestination = $otherServer->standaloneDockers()->first();

    $response = $this->post(route('project.application.clone', appTabsParams($application)), [
        'destination_id' => $otherDestination->id,
    ]);

    $response->assertSessionHasErrors('destination_id');
});

it('renders the scheduled tasks list and creates a task', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);

    $this->get(route('project.application.scheduled-tasks.show', appTabsParams($application)))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Project/Application/Configuration')
            ->where('tab', 'scheduled-tasks')
            ->has('tasks', 0)
        );

    $response = $this->post(route('project.application.scheduled-tasks.store', appTabsParams($application)), [
        'name' => 'nightly-backup',
        'command' => 'php artisan backup:run',
        'frequency' => 'daily',
        'timeout' => 300,
    ]);

    $response->assertSessionHas('success', 'Scheduled task added.');
    expect($application->scheduled_tasks()->count())->toBe(1);
});

it('redirects cross-team visitors to the dashboard', function () {
    $teamA = Team::factory()->create();
    $application = appTabsMakeApplication($teamA);
    appTabsActingAs(Team::factory()->create());

    $this->get(route('project.application.tags', appTabsParams($application)))
        ->assertRedirect(route('dashboard'));
});
