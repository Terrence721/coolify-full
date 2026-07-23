<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function boardingActingAs(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);

    return [$user, $team];
}

it('renders the onboarding page with servers, keys, and projects', function () {
    [, $team] = boardingActingAs();
    PrivateKey::create([
        'name' => 'Deploy Key',
        'private_key' => generateSSHKey('ed25519')['private'],
        'team_id' => $team->id,
    ]);
    $project = Project::factory()->create(['team_id' => $team->id, 'name' => 'My Project']);

    $response = $this->get(route('onboarding'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Boarding/Index')
        ->has('privateKeys', 1)
        ->where('privateKeys.0.name', 'Deploy Key')
        ->has('projects', 1)
        ->where('projects.0.name', 'My Project')
        ->where('projects.0.environmentUuid', $project->environments->first()->uuid)
        ->has('minDockerVersion')
        ->has('createServerUrl')
        ->has('validateUrl')
        ->has('createProjectUrl')
        ->has('skipUrl')
    );
});

it('creates a server and returns its id, uuid, and name as json', function () {
    [, $team] = boardingActingAs();
    $key = PrivateKey::create([
        'name' => 'Deploy Key',
        'private_key' => generateSSHKey('ed25519')['private'],
        'team_id' => $team->id,
    ]);

    $response = $this->postJson(route('onboarding.create-server'), [
        'name' => 'onboarding-server',
        'description' => 'Created during onboarding',
        'ip' => '192.0.2.20',
        'port' => 22,
        'user' => 'root',
        'private_key_id' => $key->id,
    ]);

    $response->assertOk();
    $server = Server::where('ip', '192.0.2.20')->first();
    expect($server)->not->toBeNull();
    expect($server->name)->toBe('onboarding-server');
    expect($server->team_id)->toBe($team->id);
    // 'id' is required here, not just uuid/name: Boarding/Index.jsx's "Deploy Your First
    // Resource" link builds its server_id query param from this response, and
    // ProjectResourceCreateController expects that param to be the server's numeric id
    // (`$servers->firstWhere('id', (int) $server_id)`), not its uuid.
    $response->assertJson(['id' => $server->id, 'uuid' => $server->uuid, 'name' => 'onboarding-server']);
});

it('exposes the localhost server\'s id, not just its uuid and name', function () {
    [, $team] = boardingActingAs();
    // Mirrors the real id-0 localhost server every instance seeds; the "This Machine" tile's
    // "Deploy Your First Resource" link needs `id` for the same server_id-must-be-numeric
    // reason as the createServer() test above.
    $server = Server::factory()->create(['id' => 0, 'team_id' => $team->id, 'name' => 'localhost']);

    $response = $this->get(route('onboarding'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Boarding/Index')
        ->where('localhostServer.id', $server->id)
        ->where('localhostServer.uuid', $server->uuid)
        ->where('localhostServer.name', 'localhost')
    );
});

it('rejects a server with an IP already used by the current team', function () {
    [, $team] = boardingActingAs();
    Server::factory()->create(['team_id' => $team->id, 'ip' => '192.0.2.21']);
    $key = PrivateKey::create([
        'name' => 'Deploy Key',
        'private_key' => generateSSHKey('ed25519')['private'],
        'team_id' => $team->id,
    ]);

    $response = $this->postJson(route('onboarding.create-server'), [
        'name' => 'dup-server',
        'ip' => '192.0.2.21',
        'port' => 22,
        'user' => 'root',
        'private_key_id' => $key->id,
    ]);

    $response->assertStatus(422);
    $response->assertJsonFragment(['message' => 'A server with this IP/Domain already exists in your team.']);
});

it('rejects a server creation request missing required fields', function () {
    boardingActingAs();

    $response = $this->postJson(route('onboarding.create-server'), ['name' => 'incomplete']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['ip', 'port', 'user', 'private_key_id']);
});

it('reports an unreachable server without touching real ssh', function () {
    [, $team] = boardingActingAs();
    // ip=1.2.3.4 is Server::skipServer()'s established sentinel — validateConnection() short
    // circuits before ever touching SSH, matching this migration's usual pattern for exercising
    // validation code paths without a real reachable server (see docs/smoketest.md).
    $server = Server::factory()->create(['team_id' => $team->id, 'ip' => '1.2.3.4']);

    $response = $this->postJson(route('onboarding.validate'), [
        'server_uuid' => $server->uuid,
        'install' => true,
        'attempt' => 0,
    ]);

    $response->assertOk();
    $response->assertJson(['status' => 'unreachable', 'error' => 'Server skipped.']);
});

it('rejects validating a server owned by another team', function () {
    boardingActingAs();
    $otherTeam = Team::factory()->create();
    $foreignServer = Server::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->postJson(route('onboarding.validate'), ['server_uuid' => $foreignServer->uuid]);

    $response->assertNotFound();
});

it('creates the default first project with a production environment', function () {
    boardingActingAs();

    $response = $this->postJson(route('onboarding.create-project'));

    $response->assertOk();
    $project = Project::where('name', 'My first project')->first();
    expect($project)->not->toBeNull();
    $response->assertJson([
        'uuid' => $project->uuid,
        'name' => 'My first project',
        'environmentUuid' => $project->environments->first()->uuid,
    ]);
});

it('skips boarding and redirects to the dashboard', function () {
    [, $team] = boardingActingAs();
    $team->update(['show_boarding' => true]);

    $response = $this->post(route('onboarding.skip'));

    $response->assertRedirect(route('dashboard'));
    expect($team->fresh()->show_boarding)->toBeFalse();
});
