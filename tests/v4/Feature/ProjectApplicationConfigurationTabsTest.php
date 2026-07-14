<?php

declare(strict_types=1);

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
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

function appTabsMakeSwarmApplication(Team $team, array $attrs = []): Application
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_swarm_manager' => true]);
    $swarmDestination = \App\Models\SwarmDocker::create(['name' => 'swarm', 'server_id' => $server->id, 'network' => 'swarm_network']);

    return appTabsMakeApplication($team, [
        'destination_id' => $swarmDestination->id,
        'destination_type' => \App\Models\SwarmDocker::class,
        ...$attrs,
    ]);
}

it('renders the swarm tab with current settings', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeSwarmApplication($team, ['swarm_replicas' => 3]);

    $response = $this->get(route('project.application.swarm', appTabsParams($application)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Application/Configuration')
        ->where('tab', 'swarm')
        ->where('swarm.swarmReplicas', 3)
        ->where('swarm.isSwarmOnlyWorkerNodes', true)
        ->has('swarmUpdateUrl')
    );
});

it('updates swarm settings, base64-encoding the placement constraints', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeSwarmApplication($team);

    $response = $this->patch(route('project.application.swarm.update', appTabsParams($application)), [
        'swarmReplicas' => 5,
        'swarmPlacementConstraints' => "placement:\n    constraints:\n        - 'node.role == worker'",
        'isSwarmOnlyWorkerNodes' => false,
    ]);

    $response->assertSessionHas('success', 'Swarm settings updated.');
    $application->refresh();
    expect($application->swarm_replicas)->toBe(5);
    expect(base64_decode($application->swarm_placement_constraints))->toContain('node.role == worker');
    expect($application->settings->fresh()->is_swarm_only_worker_nodes)->toBeFalse();
});

it('rejects a swarm update missing the required replicas field', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeSwarmApplication($team);

    $this->patch(route('project.application.swarm.update', appTabsParams($application)), [
        'isSwarmOnlyWorkerNodes' => false,
    ])->assertSessionHasErrors('swarmReplicas');
});

it('only lists the swarm tab link for a swarm destination', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $nonSwarmApplication = appTabsMakeApplication($team);
    $swarmApplication = appTabsMakeSwarmApplication($team);

    $nonSwarmLinks = $this->get(route('project.application.tags', appTabsParams($nonSwarmApplication)))
        ->viewData('page')['props']['tabs'];
    $swarmLinks = $this->get(route('project.application.tags', appTabsParams($swarmApplication)))
        ->viewData('page')['props']['tabs'];

    expect(collect($nonSwarmLinks)->pluck('key'))->not->toContain('swarm');
    expect(collect($swarmLinks)->pluck('key'))->toContain('swarm');
});

it('renders the rollback tab with current settings', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);
    $application->settings->update(['docker_images_to_keep' => 4]);

    $response = $this->get(route('project.application.rollback', appTabsParams($application)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Application/Configuration')
        ->where('tab', 'rollback')
        ->where('rollback.dockerImagesToKeep', 4)
        ->where('rollback.serverRetentionDisabled', false)
        ->where('rollback.canDeploy', true)
        ->has('rollbackUrls.saveSettings')
        ->has('rollbackUrls.loadImages')
        ->has('rollbackUrls.deploy')
    );
});

it('saves rollback settings', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);

    $response = $this->patch(route('project.application.rollback.save-settings', appTabsParams($application)), [
        'dockerImagesToKeep' => 10,
    ]);

    $response->assertSessionHas('success', 'Settings saved.');
    expect($application->settings->fresh()->docker_images_to_keep)->toBe(10);
});

it('rejects rollback settings above the allowed maximum', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);

    $this->patch(route('project.application.rollback.save-settings', appTabsParams($application)), [
        'dockerImagesToKeep' => 101,
    ])->assertSessionHasErrors('dockerImagesToKeep');
});

it('loads no images for a non-functional server, without touching SSH', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);

    $response = $this->post(route('project.application.rollback.load-images', appTabsParams($application)));

    $response->assertRedirect();
    $response->assertSessionHas('rollbackImages', []);
});

it('rejects rolling back to an invalid git ref, without queuing a deployment', function () {
    Queue::fake();
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);

    $response = $this->post(route('project.application.rollback.deploy', appTabsParams($application)), [
        'tag' => '-not-a-valid-ref',
    ]);

    $response->assertSessionHas('error');
    Queue::assertNothingPushed();
});

it('queues a rollback deployment for a valid commit tag', function () {
    Queue::fake();
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);

    $response = $this->post(route('project.application.rollback.deploy', appTabsParams($application)), [
        'tag' => 'a1b2c3d',
    ]);

    $response->assertRedirect();
    $deployment = ApplicationDeploymentQueue::where('application_id', $application->id)->firstOrFail();
    expect($deployment->rollback)->toBeTruthy();
    expect($deployment->commit)->toBe('a1b2c3d');
    Queue::assertPushed(ApplicationDeploymentJob::class);
});

function appGeneralPayload(Application $application, array $overrides = []): array
{
    return array_merge([
        'name' => $application->name,
        'description' => $application->description,
        'fqdn' => $application->fqdn,
        'gitRepository' => $application->git_repository,
        'gitBranch' => $application->git_branch,
        'gitCommitSha' => $application->git_commit_sha,
        'installCommand' => $application->install_command,
        'buildCommand' => $application->build_command,
        'startCommand' => $application->start_command,
        'buildPack' => $application->build_pack,
        'staticImage' => $application->static_image ?? 'nginx:alpine',
        'baseDirectory' => $application->base_directory ?? '/',
        'publishDirectory' => $application->publish_directory,
        'portsExposes' => $application->ports_exposes,
        'portsMappings' => $application->ports_mappings,
        'customNetworkAliases' => $application->custom_network_aliases,
        'dockerfile' => $application->dockerfile,
        'dockerRegistryImageName' => $application->docker_registry_image_name,
        'dockerRegistryImageTag' => $application->docker_registry_image_tag,
        'dockerfileLocation' => $application->dockerfile_location,
        'dockerComposeLocation' => $application->docker_compose_location,
        'dockerfileTargetBuild' => $application->dockerfile_target_build,
        'dockerComposeCustomStartCommand' => $application->docker_compose_custom_start_command,
        'dockerComposeCustomBuildCommand' => $application->docker_compose_custom_build_command,
        'customLabels' => $application->parseContainerLabels(),
        'customDockerRunOptions' => $application->custom_docker_run_options,
        'preDeploymentCommand' => $application->pre_deployment_command,
        'preDeploymentCommandContainer' => $application->pre_deployment_command_container,
        'postDeploymentCommand' => $application->post_deployment_command,
        'postDeploymentCommandContainer' => $application->post_deployment_command_container,
        'customNginxConfiguration' => $application->custom_nginx_configuration,
        'isStatic' => (bool) $application->settings->is_static,
        'isSpa' => (bool) $application->settings->is_spa,
        'isBuildServerEnabled' => (bool) $application->settings->is_build_server_enabled,
        'isContainerLabelEscapeEnabled' => (bool) $application->settings->is_container_label_escape_enabled,
        'isContainerLabelReadonlyEnabled' => (bool) $application->settings->is_container_label_readonly_enabled,
        'isPreserveRepositoryEnabled' => (bool) $application->settings->is_preserve_repository_enabled,
        'isHttpBasicAuthEnabled' => (bool) $application->is_http_basic_auth_enabled,
        'httpBasicAuthUsername' => $application->http_basic_auth_username,
        'httpBasicAuthPassword' => $application->http_basic_auth_password,
        'watchPaths' => $application->watch_paths,
        'redirect' => $application->redirect ?? 'both',
    ], $overrides);
}

it('renders the general tab with current field values', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team, ['build_pack' => 'nixpacks']);

    $response = $this->get(route('project.application.configuration', appTabsParams($application)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Application/Configuration')
        ->where('tab', 'configuration')
        ->where('general.name', 'my-app')
        ->where('general.buildPack', 'nixpacks')
        ->where('general.redirect', 'both')
        ->where('resourceDetails.resource.uuid', $application->uuid)
        ->has('generalUrls.update')
        ->has('generalUrls.instantSave')
        ->has('generalUrls.wildcardDomain')
    );
});

it('renders dockercompose-specific general props for a compose build pack', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team, [
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  app:\n    image: nginx\n",
    ]);

    $response = $this->get(route('project.application.configuration', appTabsParams($application)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('general.buildPack', 'dockercompose')
        ->where('general.dockerComposeRaw', "services:\n  app:\n    image: nginx\n")
        ->has('general.composeServices')
    );
});

it('saves the general form and updates the application', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);

    $response = $this->patch(route('project.application.general.update', appTabsParams($application)), appGeneralPayload($application, [
        'name' => 'renamed-app',
        'description' => 'a new description',
    ]));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Application settings updated!');
    $application->refresh();
    expect($application->name)->toBe('renamed-app')
        ->and($application->description)->toBe('a new description');
});

it('rejects an invalid git branch on save', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);

    $response = $this->patch(route('project.application.general.update', appTabsParams($application)), appGeneralPayload($application, [
        'gitBranch' => 'not a valid branch; rm -rf',
    ]));

    $response->assertSessionHasErrors('gitBranch');
    expect($application->refresh()->git_branch)->not->toBe('not a valid branch; rm -rf');
});

it('flags a top-level fqdn conflict instead of saving, then force-saves it', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);
    Application::factory()->create([
        'environment_id' => $application->environment_id,
        'fqdn' => 'https://taken.example.com',
    ]);

    $response = $this->patch(route('project.application.general.update', appTabsParams($application)), appGeneralPayload($application, [
        'fqdn' => 'https://taken.example.com',
    ]));

    $response->assertRedirect();
    $response->assertSessionHas('showDomainConflictModal', true);
    expect($application->refresh()->fqdn)->not->toBe('https://taken.example.com');

    $forced = $this->patch(route('project.application.general.update', appTabsParams($application)), appGeneralPayload($application, [
        'fqdn' => 'https://taken.example.com',
        'force_save_domains' => true,
    ]));

    $forced->assertSessionHas('success', 'Application settings updated!');
    expect($application->refresh()->fqdn)->toBe('https://taken.example.com');
});

it('saves the instant-save settings, regenerating nginx config when isSpa flips', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team, ['build_pack' => 'static']);

    $response = $this->patch(route('project.application.general.instant-save', appTabsParams($application)), appGeneralPayload($application, [
        'isSpa' => true,
    ]));

    $response->assertSessionHas('success', 'Settings saved.');
    $application->refresh();
    expect($application->settings->is_spa)->toBeTrue()
        ->and($application->custom_nginx_configuration)->toContain('try_files');
});

it('regenerates container labels on instant-save when readonly labels are enabled and ports change', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team, ['ports_exposes' => '3000']);
    $application->settings->update(['is_container_label_readonly_enabled' => true]);
    $application->custom_labels = base64_encode("coolify.managed=true\n");
    $application->save();
    $before = $application->custom_labels;

    $response = $this->patch(route('project.application.general.instant-save', appTabsParams($application)), appGeneralPayload($application, [
        'portsExposes' => '4000',
    ]));

    $response->assertSessionHas('success', 'Settings saved.');
    $application->refresh();
    expect($application->ports_exposes)->toBe('4000')
        ->and($application->custom_labels)->not->toBe($before);
});

it('generates a wildcard domain without touching SSH', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team, ['fqdn' => null]);

    $response = $this->post(route('project.application.general.wildcard-domain', appTabsParams($application)));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Wildcard domain generated.');
    expect($application->refresh()->fqdn)->not->toBeNull()
        ->and($application->fqdn)->toContain('sslip.io');
});

it('rejects a redirect direction save with no www domain configured', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team, ['fqdn' => 'https://app.example.com']);

    $response = $this->patch(route('project.application.general.redirect', appTabsParams($application)), [
        'redirect' => 'www',
    ]);

    $response->assertSessionHas('error');
    expect($application->refresh()->redirect)->not->toBe('www');
});

it('saves a redirect direction when a www domain is configured', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team, ['fqdn' => 'https://app.example.com,https://www.app.example.com']);

    $response = $this->patch(route('project.application.general.redirect', appTabsParams($application)), [
        'redirect' => 'www',
    ]);

    $response->assertSessionHas('success', 'Redirect updated.');
    expect($application->refresh()->redirect)->toBe('www');
});

it('resets container labels to defaults', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team);
    expect($application->custom_labels)->toBeNull();

    $response = $this->post(route('project.application.general.reset-labels', appTabsParams($application)), [
        'manual' => true,
    ]);

    $response->assertSessionHas('success', 'Labels reset to defaults.');
    expect($application->refresh()->custom_labels)->not->toBeNull();
});

it('loads no compose services for a non-functional server, without touching SSH', function () {
    $team = Team::factory()->create();
    appTabsActingAs($team);
    $application = appTabsMakeApplication($team, ['build_pack' => 'dockercompose']);

    $response = $this->post(route('project.application.general.load-compose', appTabsParams($application)), [
        'isInit' => true,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
});
