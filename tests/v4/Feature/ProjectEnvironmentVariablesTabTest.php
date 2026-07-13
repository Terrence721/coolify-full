<?php

declare(strict_types=1);

use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function envTabActingAs(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);

    return $user;
}

function envTabMakePostgres(Team $team): StandalonePostgresql
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);

    return StandalonePostgresql::create([
        'name' => 'test-postgres',
        'postgres_password' => 'secret',
        'destination_id' => $server->destinations()->first()->id,
        'destination_type' => StandaloneDocker::class,
        'environment_id' => $project->environments()->first()->id,
        'status' => 'running',
    ]);
}

function envTabParams(StandalonePostgresql|Service $resource): array
{
    $key = $resource instanceof Service ? 'service_uuid' : 'database_uuid';

    return [
        'project_uuid' => $resource->environment->project->uuid,
        'environment_uuid' => $resource->environment->uuid,
        $key => $resource->uuid,
    ];
}

function envTabAddVariable($resource, string $key, string $value, array $extra = []): EnvironmentVariable
{
    return $resource->environment_variables()->create([
        'key' => $key,
        'value' => $value,
        'resourceable_type' => $resource->getMorphClass(),
        ...$extra,
    ]);
}

it('renders the database environment variables tab', function () {
    $team = Team::factory()->create();
    envTabActingAs($team);
    $database = envTabMakePostgres($team);
    envTabAddVariable($database, 'CUSTOM_VAR', 'custom-value');

    $response = $this->get(route('project.database.environment-variables', envTabParams($database)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Database/Configuration')
        ->where('tab', 'environment-variables')
        ->where('canManageEnvironment', true)
        ->has('envUrls.store')
        ->has('devEnvs')
    );
    $envs = collect($response->viewData('page')['props']['envs']);
    expect($envs->firstWhere('key', 'CUSTOM_VAR')['value'])->toBe('custom-value');
});

it('does not send locked values to the client', function () {
    $team = Team::factory()->create();
    envTabActingAs($team);
    $database = envTabMakePostgres($team);
    envTabAddVariable($database, 'LOCKED_SECRET', 'super-secret', ['is_shown_once' => true]);

    $response = $this->get(route('project.database.environment-variables', envTabParams($database)));

    $envs = collect($response->viewData('page')['props']['envs']);
    $locked = $envs->firstWhere('key', 'LOCKED_SECRET');
    expect($locked['isLocked'])->toBeTrue();
    expect($locked['value'])->toBeNull();
});

it('creates an environment variable', function () {
    $team = Team::factory()->create();
    envTabActingAs($team);
    $database = envTabMakePostgres($team);

    $response = $this->post(route('project.database.envs.store', envTabParams($database)), [
        'key' => 'NEW_VAR',
        'value' => 'new-value',
        'comment' => 'a note',
        'is_multiline' => false,
        'is_literal' => false,
        'is_runtime' => true,
        'is_buildtime' => true,
    ]);

    $response->assertRedirect();
    $env = $database->environment_variables()->where('key', 'NEW_VAR')->firstOrFail();
    expect($env->value)->toBe('new-value');
    expect($env->comment)->toBe('a note');
});

it('rejects a duplicate environment variable key', function () {
    $team = Team::factory()->create();
    envTabActingAs($team);
    $database = envTabMakePostgres($team);
    envTabAddVariable($database, 'DUPE', 'first');

    $this->post(route('project.database.envs.store', envTabParams($database)), [
        'key' => 'DUPE',
        'value' => 'second',
        'is_multiline' => false,
        'is_literal' => false,
        'is_runtime' => true,
        'is_buildtime' => true,
    ])->assertSessionHas('error');

    expect($database->environment_variables()->where('key', 'DUPE')->count())->toBe(1);
});

it('updates an environment variable value', function () {
    $team = Team::factory()->create();
    envTabActingAs($team);
    $database = envTabMakePostgres($team);
    $env = envTabAddVariable($database, 'EDIT_ME', 'old');

    $this->patch(route('project.database.envs.update', [...envTabParams($database), 'env_id' => $env->id]), [
        'key' => 'EDIT_ME',
        'value' => 'new',
        'is_multiline' => false,
        'is_literal' => false,
        'is_runtime' => true,
        'is_buildtime' => true,
    ]);

    expect($env->fresh()->value)->toBe('new');
});

it('only updates the comment of a locked variable', function () {
    $team = Team::factory()->create();
    envTabActingAs($team);
    $database = envTabMakePostgres($team);
    $env = envTabAddVariable($database, 'LOCKED', 'secret', ['is_shown_once' => true]);

    $this->patch(route('project.database.envs.update', [...envTabParams($database), 'env_id' => $env->id]), [
        'key' => 'RENAMED',
        'value' => 'stolen',
        'comment' => 'documented',
        'is_multiline' => false,
        'is_literal' => false,
        'is_runtime' => true,
        'is_buildtime' => true,
    ]);

    $env->refresh();
    expect($env->key)->toBe('LOCKED');
    expect($env->value)->toBe('secret');
    expect($env->comment)->toBe('documented');
});

it('rejects emptying a required variable', function () {
    $team = Team::factory()->create();
    envTabActingAs($team);
    $database = envTabMakePostgres($team);
    $env = envTabAddVariable($database, 'REQUIRED_VAR', 'must-stay', ['is_required' => true]);

    $response = $this->patch(route('project.database.envs.update', [...envTabParams($database), 'env_id' => $env->id]), [
        'key' => 'REQUIRED_VAR',
        'value' => '',
        'is_multiline' => false,
        'is_literal' => false,
        'is_runtime' => true,
        'is_buildtime' => true,
    ]);

    $response->assertSessionHas('error');
    expect($env->fresh()->value)->toBe('must-stay');
});

it('locks a variable', function () {
    $team = Team::factory()->create();
    envTabActingAs($team);
    $database = envTabMakePostgres($team);
    $env = envTabAddVariable($database, 'TO_LOCK', 'value');

    $this->post(route('project.database.envs.lock', [...envTabParams($database), 'env_id' => $env->id]));

    expect((bool) $env->fresh()->is_shown_once)->toBeTrue();
});

it('deletes a variable', function () {
    $team = Team::factory()->create();
    envTabActingAs($team);
    $database = envTabMakePostgres($team);
    $env = envTabAddVariable($database, 'DOOMED', 'value');

    $this->delete(route('project.database.envs.destroy', [...envTabParams($database), 'env_id' => $env->id]));

    expect(EnvironmentVariable::find($env->id))->toBeNull();
});

it('bulk-updates variables from the developer view, preserving order', function () {
    $team = Team::factory()->create();
    envTabActingAs($team);
    $database = envTabMakePostgres($team);
    envTabAddVariable($database, 'KEEP', 'old-value');
    envTabAddVariable($database, 'REMOVE_ME', 'bye');

    $response = $this->patch(route('project.database.envs.bulk-update', envTabParams($database)), [
        'variables' => "BRAND_NEW=hello\nKEEP=new-value",
    ]);

    $response->assertRedirect();
    expect($database->environment_variables()->where('key', 'REMOVE_ME')->exists())->toBeFalse();
    expect($database->environment_variables()->where('key', 'KEEP')->first()->value)->toBe('new-value');
    $new = $database->environment_variables()->where('key', 'BRAND_NEW')->first();
    expect($new->value)->toBe('hello');
    expect($new->order)->toBe(1);
    expect($database->environment_variables()->where('key', 'KEEP')->first()->order)->toBe(2);
});

it('renders the service environment variables tab with hardcoded compose variables', function () {
    $team = Team::factory()->create();
    envTabActingAs($team);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $service = Service::factory()->create([
        'name' => 'test-service',
        'environment_id' => $project->environments()->first()->id,
        'server_id' => $server->id,
        'destination_id' => $server->destinations()->first()->id,
        'destination_type' => StandaloneDocker::class,
        'docker_compose_raw' => "services:\n  app:\n    image: nginx\n    environment:\n      - HARDCODED_VAR=fixed\n",
    ]);

    $response = $this->get(route('project.service.environment-variables', envTabParams($service)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Service/Configuration')
        ->where('tab', 'environment-variables')
    );
    $hardcoded = collect($response->viewData('page')['props']['hardcodedEnvs']);
    expect($hardcoded->pluck('key'))->toContain('HARDCODED_VAR');
});

it('blocks deleting a service variable still used in docker compose', function () {
    $team = Team::factory()->create();
    envTabActingAs($team);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $service = Service::factory()->create([
        'name' => 'test-service',
        'environment_id' => $project->environments()->first()->id,
        'server_id' => $server->id,
        'destination_id' => $server->destinations()->first()->id,
        'destination_type' => StandaloneDocker::class,
        'docker_compose' => "services:\n  app:\n    image: nginx\n    environment:\n      USED_VAR: in-use\n",
    ]);
    $env = envTabAddVariable($service, 'USED_VAR', 'in-use');

    $response = $this->delete(route('project.service.envs.destroy', [...envTabParams($service), 'env_id' => $env->id]));

    $response->assertSessionHas('error');
    expect(EnvironmentVariable::find($env->id))->not->toBeNull();
});
