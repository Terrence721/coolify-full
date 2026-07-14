<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\CloudProviderToken;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function serverShowActingAs(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);

    return [$user, $team];
}

it('renders the server show Inertia page', function () {
    [, $team] = serverShowActingAs();
    $server = Server::factory()->create(['team_id' => $team->id, 'name' => 'My Server']);

    $response = $this->get(route('server.show', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/Show')
        ->where('server.name', 'My Server')
        ->where('sidebar.variant', 'main')
        ->where('sidebar.activeMenu', 'general')
        ->has('timezones')
        ->has('urls.update')
        ->has('urls.validate')
    );
});

it('updates general server settings', function () {
    [, $team] = serverShowActingAs();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->patch(route('server.show.update', ['server_uuid' => $server->uuid]), [
        'name' => 'Renamed Server',
        'description' => 'A new description',
        'ip' => '192.0.2.30',
        'user' => 'root',
        'port' => 22,
        'connectionTimeout' => 15,
        'wildcardDomain' => 'https://example.com',
        'serverTimezone' => 'UTC',
    ]);

    $response->assertRedirect();
    $server->refresh();
    expect($server->name)->toBe('Renamed Server');
    expect($server->ip)->toBe('192.0.2.30');
    expect($server->settings->connection_timeout)->toBe(15);
    expect($server->settings->server_timezone)->toBe('UTC');
});

it('rejects a duplicate ip when updating general settings', function () {
    [, $team] = serverShowActingAs();
    Server::factory()->create(['team_id' => $team->id, 'ip' => '192.0.2.31']);
    $server = Server::factory()->create(['team_id' => $team->id, 'ip' => '192.0.2.32']);

    $response = $this->patch(route('server.show.update', ['server_uuid' => $server->uuid]), [
        'name' => $server->name,
        'ip' => '192.0.2.31',
        'user' => 'root',
        'port' => 22,
        'connectionTimeout' => 10,
        'serverTimezone' => 'UTC',
    ]);

    $response->assertSessionHas('error', 'A server with this IP/Domain already exists in your team.');
    expect($server->fresh()->ip)->toBe('192.0.2.32');
});

it('rejects an invalid timezone when updating general settings', function () {
    [, $team] = serverShowActingAs();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->patch(route('server.show.update', ['server_uuid' => $server->uuid]), [
        'name' => $server->name,
        'ip' => $server->ip,
        'user' => 'root',
        'port' => 22,
        'connectionTimeout' => 10,
        'serverTimezone' => 'Not/AZone',
    ]);

    $response->assertSessionHas('error', 'Invalid timezone.');
});

it('toggles the build-server flag on an empty server', function () {
    [, $team] = serverShowActingAs();
    $server = Server::factory()->create(['team_id' => $team->id]);
    expect($server->isEmpty())->toBeTrue();

    $response = $this->post(route('server.show.instant-save-build-server', ['server_uuid' => $server->uuid]), [
        'isBuildServer' => true,
    ]);

    $response->assertRedirect();
    expect((bool) $server->fresh()->settings->is_build_server)->toBeTrue();
});

it('rejects toggling the build-server flag on a server with resources', function () {
    [, $team] = serverShowActingAs();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->destinations()->first();
    Application::factory()->create([
        'destination_id' => $destination->id,
        'destination_type' => $destination::class,
        'environment_id' => Environment::factory()->create([
            'project_id' => Project::factory()->create(['team_id' => $team->id])->id,
        ])->id,
    ]);

    $response = $this->post(route('server.show.instant-save-build-server', ['server_uuid' => $server->uuid]), [
        'isBuildServer' => true,
    ]);

    $response->assertSessionHas('error', "You can't use this server as a build server because it has defined resources.");
    expect((bool) $server->fresh()->settings->is_build_server)->toBeFalse();
});

it('reports an unreachable server when validating without touching real ssh', function () {
    [, $team] = serverShowActingAs();
    // ip=1.2.3.4 is Server::skipServer()'s established sentinel — validateConnection() short
    // circuits before ever touching SSH, matching this migration's usual pattern.
    $server = Server::factory()->create(['team_id' => $team->id, 'ip' => '1.2.3.4']);

    $response = $this->postJson(route('server.show.validate', ['server_uuid' => $server->uuid]), [
        'install' => true,
        'attempt' => 0,
    ]);

    $response->assertOk();
    $response->assertJson(['status' => 'unreachable', 'error' => 'Server skipped.']);
    expect((bool) $server->fresh()->is_validating)->toBeFalse();
});

it('reports an unreachable localhost connection check without touching real ssh', function () {
    [, $team] = serverShowActingAs();
    $server = Server::factory()->create(['team_id' => $team->id, 'ip' => '1.2.3.4']);

    $response = $this->post(route('server.show.check-localhost', ['server_uuid' => $server->uuid]));

    $response->assertRedirect();
    $response->assertSessionHas('error');
});

it('rejects hetzner status check for a server without a linked token', function () {
    [, $team] = serverShowActingAs();
    $server = Server::factory()->create(['team_id' => $team->id, 'hetzner_server_id' => null]);

    $response = $this->postJson(route('server.show.hetzner-status', ['server_uuid' => $server->uuid]));

    $response->assertStatus(422);
});

it('searches for a hetzner server by ip and reports a match', function () {
    Http::fake([
        'api.hetzner.cloud/*' => Http::response(['servers' => [
            ['id' => 555, 'name' => 'hz-box', 'status' => 'running', 'public_net' => ['ipv4' => ['ip' => '192.0.2.40']], 'server_type' => ['name' => 'cx11']],
        ]], 200),
    ]);

    [, $team] = serverShowActingAs();
    $server = Server::factory()->create(['team_id' => $team->id, 'ip' => '192.0.2.40', 'hetzner_server_id' => null]);
    $token = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'hetzner', 'token' => 'abc', 'name' => 'My Token']);

    $response = $this->postJson(route('server.show.hetzner-search-ip', ['server_uuid' => $server->uuid]), [
        'token_id' => $token->id,
    ]);

    $response->assertOk();
    $response->assertJson(['match' => ['id' => 555, 'name' => 'hz-box']]);
});

it('links a server to a matched hetzner server', function () {
    Http::fake([
        'api.hetzner.cloud/*' => Http::response(['server' => ['id' => 555, 'name' => 'hz-box', 'status' => 'running']], 200),
    ]);

    [, $team] = serverShowActingAs();
    $server = Server::factory()->create(['team_id' => $team->id, 'hetzner_server_id' => null]);
    $token = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'hetzner', 'token' => 'abc', 'name' => 'My Token']);

    $response = $this->post(route('server.show.hetzner-link', ['server_uuid' => $server->uuid]), [
        'token_id' => $token->id,
        'hetzner_server_id' => 555,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Server successfully linked to Hetzner Cloud!');
    $server->refresh();
    expect($server->hetzner_server_id)->toBe(555);
    expect($server->cloud_provider_token_id)->toBe($token->id);
});

it('rejects server show actions for a server owned by another team', function () {
    serverShowActingAs();
    $otherTeam = Team::factory()->create();
    $foreignServer = Server::factory()->create(['team_id' => $otherTeam->id]);

    $this->get(route('server.show', ['server_uuid' => $foreignServer->uuid]))->assertNotFound();
    $this->postJson(route('server.show.validate', ['server_uuid' => $foreignServer->uuid]))->assertNotFound();
});
