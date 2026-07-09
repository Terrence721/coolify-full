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

it('renders the security cloud tokens Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('security.cloud-tokens'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Security/CloudTokens')
        ->where('canCreate', true)
        ->has('tokens', 0)
        ->where('storeUrl', route('security.cloud-tokens.store'))
    );
});

it('forbids cloud tokens access for a non-admin member', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'member']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('security.cloud-tokens'));

    $response->assertForbidden();
});

it('adds a new cloud provider token once the provider API validates it', function () {
    Http::fake(['api.hetzner.cloud/*' => Http::response(['servers' => []], 200)]);
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('security.cloud-tokens.store'), [
            'provider' => 'hetzner',
            'name' => 'Production Hetzner',
            'token' => 'fake-token',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect(CloudProviderToken::where('team_id', $team->id)->where('name', 'Production Hetzner')->exists())->toBeTrue();
});

it('rejects a cloud provider token the provider API cannot validate', function () {
    Http::fake(['api.hetzner.cloud/*' => Http::response([], 401)]);
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('security.cloud-tokens.store'), [
            'provider' => 'hetzner',
            'name' => 'Bad Token',
            'token' => 'fake-token',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect(CloudProviderToken::where('team_id', $team->id)->where('name', 'Bad Token')->exists())->toBeFalse();
});

it('blocks deleting a cloud provider token that is still in use by a server', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $token = CloudProviderToken::create([
        'team_id' => $team->id,
        'provider' => 'hetzner',
        'token' => 'fake-token',
        'name' => 'In Use Token',
    ]);
    Server::factory()->create(['team_id' => $team->id, 'cloud_provider_token_id' => $token->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('security.cloud-tokens.destroy', ['id' => $token->id]));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect(CloudProviderToken::find($token->id))->not->toBeNull();
});

it('deletes an unused cloud provider token', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $token = CloudProviderToken::create([
        'team_id' => $team->id,
        'provider' => 'hetzner',
        'token' => 'fake-token',
        'name' => 'Unused Token',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('security.cloud-tokens.destroy', ['id' => $token->id]));

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect(CloudProviderToken::find($token->id))->toBeNull();
});
