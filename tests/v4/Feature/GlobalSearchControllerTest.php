<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function globalSearchActingAs(string $role = 'admin'): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => $role]);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);

    return [$user, $team];
}

it('returns searchable items, creatable items, and create urls', function () {
    [, $team] = globalSearchActingAs();
    Project::factory()->create(['team_id' => $team->id, 'name' => 'My Project']);

    $response = $this->getJson(route('search.data'));

    $response->assertOk();
    $response->assertJsonStructure(['searchableItems', 'creatableItems', 'createUrls' => ['project', 'team', 'storage', 'privateKey', 'privateKeyGenerate']]);
    expect(collect($response->json('searchableItems'))->pluck('name'))->toContain('My Project');
    expect(collect($response->json('creatableItems'))->pluck('type'))->toContain('server');
});

it('hides admin-only quick actions from a plain team member', function () {
    globalSearchActingAs('member');

    $response = $this->getJson(route('search.data'));

    $response->assertOk();
    $types = collect($response->json('creatableItems'))->pluck('type');
    expect($types)->not->toContain('server')
        ->and($types)->not->toContain('storage')
        ->and($types)->not->toContain('private-key');
});

it('returns server-creation form data', function () {
    [, $team] = globalSearchActingAs();
    PrivateKey::create([
        'name' => 'Deploy Key',
        'private_key' => generateSSHKey('ed25519')['private'],
        'team_id' => $team->id,
    ]);

    $response = $this->getJson(route('search.server-create-data'));

    $response->assertOk();
    $response->assertJsonStructure(['privateKeys', 'defaultPrivateKeyId', 'defaultName', 'storeUrl']);
    expect(collect($response->json('privateKeys'))->pluck('name'))->toContain('Deploy Key');
});

it('lists only usable servers owned by the current team', function () {
    [, $team] = globalSearchActingAs();
    $usable = Server::factory()->create(['team_id' => $team->id, 'name' => 'Usable Server']);
    $usable->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $unusable = Server::factory()->create(['team_id' => $team->id, 'name' => 'Unusable Server']);
    $unusable->settings()->update(['is_reachable' => false, 'is_usable' => false]);

    $response = $this->getJson(route('search.servers'));

    $response->assertOk();
    $names = collect($response->json('servers'))->pluck('name');
    expect($names)->toContain('Usable Server')
        ->and($names)->not->toContain('Unusable Server');
});

it('returns destinations for an owned server', function () {
    [, $team] = globalSearchActingAs();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->getJson(route('search.destinations', ['server_id' => $server->id]));

    $response->assertOk();
    expect($response->json('destinations'))->toHaveCount(1);
});

it('rejects a destinations lookup for a server owned by another team', function () {
    globalSearchActingAs();
    $otherTeam = Team::factory()->create();
    $foreignServer = Server::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->getJson(route('search.destinations', ['server_id' => $foreignServer->id]));

    $response->assertNotFound();
});

it('returns the current team projects', function () {
    [, $team] = globalSearchActingAs();
    Project::factory()->create(['team_id' => $team->id, 'name' => 'Team Project']);

    $response = $this->getJson(route('search.projects'));

    $response->assertOk();
    expect(collect($response->json('projects'))->pluck('name'))->toContain('Team Project');
});

it('reports no projects when the team has none', function () {
    globalSearchActingAs();

    $response = $this->getJson(route('search.projects'));

    $response->assertNotFound();
});

it('returns environments for an owned project', function () {
    [, $team] = globalSearchActingAs();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);

    $response = $this->getJson(route('search.environments', ['project_uuid' => $project->uuid]));

    $response->assertOk();
    expect(collect($response->json('environments'))->pluck('uuid'))->toContain($environment->uuid);
});

it('rejects an environments lookup for a nonexistent project', function () {
    globalSearchActingAs();

    $response = $this->getJson(route('search.environments', ['project_uuid' => 'does-not-exist']));

    $response->assertNotFound();
});
