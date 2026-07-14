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

it('renders the heading props alongside a tab', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team, ['build_pack' => 'nixpacks']);

    $response = $this->get(route('project.application.tags', appTabsParams($application)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Application/Configuration')
        ->where('application.uuid', $application->uuid)
        ->where('application.buildPack', 'nixpacks')
        ->where('application.hasDockerCompose', false)
        ->where('application.isSwarm', false)
        ->has('heading.lastDeploymentInfo')
        ->has('headingUrls.deploy')
        ->has('headingUrls.restart')
        ->has('headingUrls.stop')
        ->has('headingUrls.checkStatus')
    );
});

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

it('renders the environment variables tab and creates a variable', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);

    $this->get(route('project.application.environment-variables', appTabsParams($application)))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Project/Application/Configuration')
            ->where('tab', 'environment-variables')
            ->has('envs')
        );

    $response = $this->post(route('project.application.envs.store', appTabsParams($application)), [
        'key' => 'MY_VAR',
        'value' => 'hello',
        'is_multiline' => false,
        'is_literal' => false,
        'is_runtime' => true,
        'is_buildtime' => true,
    ]);

    $response->assertSessionHas('success', 'Environment variable added.');
    expect($application->environment_variables()->where('key', 'MY_VAR')->exists())->toBeTrue();
});

it('renders hardcoded compose variables for a dockercompose-build-pack application', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team, [
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  app:\n    image: nginx\n    environment:\n      - HARDCODED_VAR=fixed\n",
    ]);

    $response = $this->get(route('project.application.environment-variables', appTabsParams($application)));

    $response->assertOk();
    $hardcoded = collect($response->viewData('page')['props']['hardcodedEnvs']);
    expect($hardcoded->pluck('key'))->toContain('HARDCODED_VAR');
});

it('blocks deleting a dockercompose application variable still used in the compose file', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team, [
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  app:\n    image: nginx\n    environment:\n      USED_VAR: in-use\n",
    ]);
    $env = $application->environment_variables()->create([
        'key' => 'USED_VAR',
        'value' => 'in-use',
        'resourceable_type' => $application->getMorphClass(),
    ]);

    $response = $this->delete(route('project.application.envs.destroy', [...appTabsParams($application), 'env_id' => $env->id]));

    $response->assertSessionHas('error');
    expect($application->environment_variables()->find($env->id))->not->toBeNull();
});

it('renders the persistent-storage tab and adds a volume and a file mount', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);

    $this->get(route('project.application.persistent-storage', appTabsParams($application)))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Project/Application/Configuration')
            ->where('tab', 'persistent-storage')
            ->where('isService', false)
            ->where('canAddMounts', true)
        );

    $this->post(route('project.application.storages.volume.store', appTabsParams($application)), [
        'name' => 'data',
        'mount_path' => '/data',
    ])->assertSessionHas('success', 'Volume added successfully');
    expect($application->persistentStorages()->where('name', $application->uuid.'-data')->exists())->toBeTrue();

    $this->post(route('project.application.storages.file.store', appTabsParams($application)), [
        'file_storage_path' => 'etc/config.conf',
        'file_storage_content' => 'setting=1',
    ]);
    $file = $application->fileStorages()->firstOrFail();
    expect($file->fs_path)->toContain('/applications/'.$application->uuid.'/etc/config.conf');
});

it('requires a host path for a volume on a swarm destination', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_swarm_manager' => true]);
    $swarmDestination = \App\Models\SwarmDocker::create(['name' => 'swarm', 'server_id' => $server->id, 'network' => 'swarm_network']);
    $application = appTabsMakeApplication($team, [
        'destination_id' => $swarmDestination->id,
        'destination_type' => \App\Models\SwarmDocker::class,
    ]);

    $this->post(route('project.application.storages.volume.store', appTabsParams($application)), [
        'name' => 'data',
        'mount_path' => '/data',
    ])->assertSessionHasErrors('host_path');

    $this->post(route('project.application.storages.volume.store', appTabsParams($application)), [
        'name' => 'data',
        'mount_path' => '/data',
        'host_path' => '/srv/data',
    ])->assertSessionHas('success', 'Volume added successfully');
});

it('renders the manual git webhooks form for a non-git-app application', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team, ['source_id' => null]);

    $response = $this->get(route('project.application.webhooks', appTabsParams($application)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Application/Configuration')
        ->where('tab', 'webhooks')
        ->has('deployWebhook')
        ->where('manualWebhooks.usesOfficialGitApp', false)
        ->has('manualWebhooks.providers.github.url')
        ->has('manualWebhooks.providers.gitlab.url')
        ->has('manualWebhooks.providers.bitbucket.url')
        ->has('manualWebhooks.providers.gitea.url')
    );
});

it('shows the official-git-app callout instead of manual webhooks when a source is connected', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team, ['source_id' => 999]);

    $response = $this->get(route('project.application.webhooks', appTabsParams($application)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('manualWebhooks.usesOfficialGitApp', true)
        ->where('manualWebhooks.providers', [])
    );
});

it('updates the manual git webhook secrets', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team, ['source_id' => null]);

    $response = $this->post(route('project.application.webhooks.update', appTabsParams($application)), [
        'githubManualWebhookSecret' => 'gh-secret',
        'gitlabManualWebhookSecret' => 'gl-secret',
        'bitbucketManualWebhookSecret' => 'bb-secret',
        'giteaManualWebhookSecret' => 'ge-secret',
    ]);

    $response->assertSessionHas('success', 'Secret Saved.');
    $application->refresh();
    expect($application->manual_webhook_secret_github)->toBe('gh-secret');
    expect($application->manual_webhook_secret_gitlab)->toBe('gl-secret');
    expect($application->manual_webhook_secret_bitbucket)->toBe('bb-secret');
    expect($application->manual_webhook_secret_gitea)->toBe('ge-secret');
});
