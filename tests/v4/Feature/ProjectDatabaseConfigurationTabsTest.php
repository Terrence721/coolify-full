<?php

declare(strict_types=1);

use App\Jobs\DeleteResourceJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
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

function dbTabsActingAs(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);

    return $user;
}

function dbTabsMakePostgres(Team $team): StandalonePostgresql
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->destinations()->first();

    return StandalonePostgresql::create([
        'name' => 'test-postgres',
        'postgres_password' => 'secret',
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'environment_id' => $environment->id,
        'status' => 'running',
    ]);
}

function dbTabsParams(StandalonePostgresql $database): array
{
    return [
        'project_uuid' => $database->environment->project->uuid,
        'environment_uuid' => $database->environment->uuid,
        'database_uuid' => $database->uuid,
    ];
}

// ---------------------------------------------------------------------------
// Tab rendering
// ---------------------------------------------------------------------------

it('renders the tags tab with assigned and available tags', function () {
    $team = Team::factory()->create();
    dbTabsActingAs($team);
    $database = dbTabsMakePostgres($team);
    $assigned = Tag::create(['name' => 'prod', 'team_id' => $team->id]);
    Tag::create(['name' => 'unassigned', 'team_id' => $team->id]);
    $database->tags()->attach($assigned->id);

    $response = $this->get(route('project.database.tags', dbTabsParams($database)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Database/Configuration')
        ->where('tab', 'tags')
        ->has('tags', 1)
        ->where('tags.0.name', 'prod')
        ->has('availableTags', 1)
        ->where('availableTags.0.name', 'unassigned')
    );
});

it('renders the danger tab with the resource name', function () {
    $team = Team::factory()->create();
    dbTabsActingAs($team);
    $database = dbTabsMakePostgres($team);

    $response = $this->get(route('project.database.danger', dbTabsParams($database)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Database/Configuration')
        ->where('tab', 'danger')
        ->where('resourceName', 'test-postgres')
        ->where('canDelete', true)
    );
});

it('renders the webhooks tab with the deploy webhook url', function () {
    $team = Team::factory()->create();
    dbTabsActingAs($team);
    $database = dbTabsMakePostgres($team);

    $response = $this->get(route('project.database.webhooks', dbTabsParams($database)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Database/Configuration')
        ->where('tab', 'webhooks')
        ->has('deployWebhook')
        ->where('manualWebhooks', null)
    );
});

it('renders the resource limits tab with current limits', function () {
    $team = Team::factory()->create();
    dbTabsActingAs($team);
    $database = dbTabsMakePostgres($team);

    $response = $this->get(route('project.database.resource-limits', dbTabsParams($database)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Database/Configuration')
        ->where('tab', 'resource-limits')
        ->has('limits.limitsMemory')
    );
});

it('renders the resource operations tab with servers and projects', function () {
    $team = Team::factory()->create();
    dbTabsActingAs($team);
    $database = dbTabsMakePostgres($team);

    $response = $this->get(route('project.database.resource-operations', dbTabsParams($database)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Database/Configuration')
        ->where('tab', 'resource-operations')
        ->has('servers', 1)
        ->has('projects', 1)
    );
});

it('renders the servers tab with the primary destination', function () {
    $team = Team::factory()->create();
    dbTabsActingAs($team);
    $database = dbTabsMakePostgres($team);

    $response = $this->get(route('project.database.servers', dbTabsParams($database)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Database/Configuration')
        ->where('tab', 'servers')
        ->has('primaryServer.name')
        ->has('primaryServer.network')
    );
});

it('redirects to the dashboard for a database of another team', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    dbTabsActingAs($team);
    $database = dbTabsMakePostgres($otherTeam);

    $response = $this->get(route('project.database.tags', dbTabsParams($database)));

    $response->assertRedirect(route('dashboard'));
});

// ---------------------------------------------------------------------------
// Tags actions
// ---------------------------------------------------------------------------

it('creates and attaches tags from a space-separated list', function () {
    $team = Team::factory()->create();
    dbTabsActingAs($team);
    $database = dbTabsMakePostgres($team);

    $response = $this->post(route('project.database.tags.store', dbTabsParams($database)), [
        'tags' => 'prod app1',
    ]);

    $response->assertRedirect();
    expect($database->tags()->pluck('name')->all())->toBe(['prod', 'app1']);
});

it('attaches an existing tag by id', function () {
    $team = Team::factory()->create();
    dbTabsActingAs($team);
    $database = dbTabsMakePostgres($team);
    $tag = Tag::create(['name' => 'existing', 'team_id' => $team->id]);

    $this->post(route('project.database.tags.store', dbTabsParams($database)), [
        'tag_id' => $tag->id,
    ]);

    expect($database->tags()->pluck('name')->all())->toBe(['existing']);
});

it('detaches a tag and prunes it when orphaned', function () {
    $team = Team::factory()->create();
    dbTabsActingAs($team);
    $database = dbTabsMakePostgres($team);
    $tag = Tag::create(['name' => 'prunable', 'team_id' => $team->id]);
    $database->tags()->attach($tag->id);

    $this->delete(route('project.database.tags.destroy', [...dbTabsParams($database), 'tag_id' => $tag->id]));

    expect($database->tags()->count())->toBe(0);
    expect(Tag::find($tag->id))->toBeNull();
});

// ---------------------------------------------------------------------------
// Resource limits
// ---------------------------------------------------------------------------

it('saves resource limits', function () {
    $team = Team::factory()->create();
    dbTabsActingAs($team);
    $database = dbTabsMakePostgres($team);

    $response = $this->patch(route('project.database.resource-limits.update', dbTabsParams($database)), [
        'limitsMemory' => '512m',
        'limitsMemorySwap' => '1g',
        'limitsMemorySwappiness' => 30,
        'limitsMemoryReservation' => '256m',
        'limitsCpus' => '1.5',
        'limitsCpuset' => '0-2',
        'limitsCpuShares' => 512,
    ]);

    $response->assertRedirect();
    $database->refresh();
    expect($database->limits_memory)->toBe('512m');
    expect($database->limits_cpus)->toBe('1.5');
    expect($database->limits_memory_swappiness)->toBe(30);
});

it('rejects an invalid memory limit format', function () {
    $team = Team::factory()->create();
    dbTabsActingAs($team);
    $database = dbTabsMakePostgres($team);

    $response = $this->patch(route('project.database.resource-limits.update', dbTabsParams($database)), [
        'limitsMemory' => 'lots',
        'limitsMemorySwap' => '0',
        'limitsMemorySwappiness' => 60,
        'limitsMemoryReservation' => '0',
    ]);

    $response->assertSessionHasErrors('limitsMemory');
});

// ---------------------------------------------------------------------------
// Resource operations
// ---------------------------------------------------------------------------

it('moves the database to another environment', function () {
    $team = Team::factory()->create();
    dbTabsActingAs($team);
    $database = dbTabsMakePostgres($team);
    $project = $database->environment->project;
    $newEnvironment = Environment::factory()->create(['project_id' => $project->id, 'name' => 'staging']);

    $response = $this->post(route('project.database.move', dbTabsParams($database)), [
        'environment_id' => $newEnvironment->id,
    ]);

    expect($database->fresh()->environment_id)->toBe($newEnvironment->id);
    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('#resource-operations');
});

it('clones the database to another destination', function () {
    $team = Team::factory()->create();
    dbTabsActingAs($team);
    $database = dbTabsMakePostgres($team);
    $database->environment_variables()->create([
        'key' => 'CUSTOM_VAR',
        'value' => 'value',
        'resourceable_type' => $database->getMorphClass(),
    ]);
    $secondServer = Server::factory()->create(['team_id' => $team->id]);
    $newDestination = $secondServer->destinations()->first();

    $response = $this->post(route('project.database.clone', dbTabsParams($database)), [
        'destination_id' => $newDestination->id,
        'clone_volume_data' => false,
    ]);

    $clone = StandalonePostgresql::where('destination_id', $newDestination->id)->where('id', '!=', $database->id)->firstOrFail();
    expect($clone->name)->toContain('-clone-');
    expect($clone->status)->toContain('exited');
    expect($clone->environment_variables()->where('key', 'CUSTOM_VAR')->exists())->toBeTrue();
    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain($clone->uuid);
});

// ---------------------------------------------------------------------------
// Danger zone
// ---------------------------------------------------------------------------

it('rejects deletion with a wrong password', function () {
    $team = Team::factory()->create();
    dbTabsActingAs($team);
    $database = dbTabsMakePostgres($team);
    Queue::fake();

    $response = $this->delete(route('project.database.destroy', dbTabsParams($database)), [
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHas('error');
    expect(StandalonePostgresql::find($database->id))->not->toBeNull();
    Queue::assertNothingPushed();
});

it('deletes the database with the correct password and dispatches the cleanup job', function () {
    $team = Team::factory()->create();
    dbTabsActingAs($team);
    $database = dbTabsMakePostgres($team);
    Queue::fake();

    $response = $this->delete(route('project.database.destroy', dbTabsParams($database)), [
        'password' => 'password',
        'delete_volumes' => true,
        'docker_cleanup' => true,
    ]);

    expect(StandalonePostgresql::find($database->id))->toBeNull();
    Queue::assertPushed(DeleteResourceJob::class);
    $response->assertRedirect(route('project.resource.index', [
        'project_uuid' => $database->environment->project->uuid,
        'environment_uuid' => $database->environment->uuid,
    ]));
});
