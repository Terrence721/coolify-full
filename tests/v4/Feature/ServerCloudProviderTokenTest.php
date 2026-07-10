<?php

declare(strict_types=1);

use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
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

it('renders the server cloud provider token Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id, 'hetzner_server_id' => 12345]);
    CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'hetzner', 'token' => 'abc', 'name' => 'My Token']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.cloud-provider-token', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/CloudProviderToken')
        ->has('serverNavbar')
        ->has('sidebar')
        ->where('hasHetznerServerId', true)
        ->has('tokens', 1)
        ->where('canUpdate', true)
        ->where('canCreate', true)
    );
});

it('shows the non-hetzner message when the server has no hetzner_server_id', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id, 'hetzner_server_id' => null]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.cloud-provider-token', ['server_uuid' => $server->uuid]));

    $response->assertInertia(fn (Assert $page) => $page->where('hasHetznerServerId', false));
});

it('returns 404 for a server owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.cloud-provider-token', ['server_uuid' => $server->uuid]));

    $response->assertNotFound();
});

it('rejects using a token not owned by the current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $otherTeam = Team::factory()->create();
    $foreignToken = CloudProviderToken::create(['team_id' => $otherTeam->id, 'provider' => 'hetzner', 'token' => 'abc', 'name' => 'Foreign']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.cloud-provider-token.set', ['server_uuid' => $server->uuid]), [
            'token_id' => $foreignToken->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'You are not allowed to use this token.');
});

it('associates a valid token with the server', function () {
    Http::fake([
        'api.hetzner.cloud/*' => Http::response(['servers' => []], 200),
    ]);

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id, 'hetzner_server_id' => null]);
    $token = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'hetzner', 'token' => 'abc', 'name' => 'My Token']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.cloud-provider-token.set', ['server_uuid' => $server->uuid]), [
            'token_id' => $token->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Hetzner token updated successfully.');
    expect($server->fresh()->cloud_provider_token_id)->toBe($token->id);
});

it('rejects an invalid token when associating it with the server', function () {
    Http::fake([
        'api.hetzner.cloud/*' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id, 'hetzner_server_id' => null]);
    $token = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'hetzner', 'token' => 'abc', 'name' => 'My Token']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.cloud-provider-token.set', ['server_uuid' => $server->uuid]), [
            'token_id' => $token->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'This token is invalid or has insufficient permissions.');
    expect($server->fresh()->cloud_provider_token_id)->toBeNull();
});

it('reports no token associated when validating without one', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.cloud-provider-token.validate', ['server_uuid' => $server->uuid]));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'No Hetzner token is associated with this server.');
});

it('validates an associated token successfully', function () {
    Http::fake([
        'api.hetzner.cloud/*' => Http::response(['servers' => []], 200),
    ]);

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $token = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'hetzner', 'token' => 'abc', 'name' => 'My Token']);
    $server = Server::factory()->create(['team_id' => $team->id, 'cloud_provider_token_id' => $token->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.cloud-provider-token.validate', ['server_uuid' => $server->uuid]));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Hetzner token is valid and working.');
});

it('creates a new cloud provider token via the modal endpoint', function () {
    Http::fake([
        'api.hetzner.cloud/*' => Http::response(['servers' => []], 200),
    ]);

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.cloud-provider-token.store', ['server_uuid' => $server->uuid]), [
            'name' => 'New Token',
            'token' => 'sometoken',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Cloud provider token added successfully.');
    expect(CloudProviderToken::where('name', 'New Token')->where('team_id', $team->id)->exists())->toBeTrue();
});

it('rejects creating a token that fails hetzner validation', function () {
    Http::fake([
        'api.hetzner.cloud/*' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.cloud-provider-token.store', ['server_uuid' => $server->uuid]), [
            'name' => 'New Token',
            'token' => 'sometoken',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Invalid API token. Please check your token and try again.');
    expect(CloudProviderToken::where('name', 'New Token')->exists())->toBeFalse();
});

it('denies a non-admin from creating a cloud provider token', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'member']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.cloud-provider-token.store', ['server_uuid' => $server->uuid]), [
            'name' => 'New Token',
            'token' => 'sometoken',
        ]);

    $response->assertForbidden();
});
