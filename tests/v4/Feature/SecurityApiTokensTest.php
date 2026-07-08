<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// InstanceSettings::get() reads a singleton row hardcoded to id 0, seeded at install time in
// real deployments — SecurityApiTokensController::edit() reads is_api_enabled from it directly.
beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the security api tokens Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('security.api-tokens'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Security/ApiTokens')
        ->has('tokens', 0)
        ->where('storeUrl', route('security.api-tokens.store'))
    );
});

it('creates a new api token', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('security.api-tokens.store'), [
            'description' => 'My token',
            'expires_in_days' => 30,
            'permissions' => ['read'],
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('token');
    expect($user->fresh()->tokens()->where('name', 'My token')->exists())->toBeTrue();
});

it('rejects root permissions for a non-admin user', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'member']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->from(route('security.api-tokens'))
        ->post(route('security.api-tokens.store'), [
            'description' => 'Root token',
            'permissions' => ['root'],
        ]);

    $response->assertRedirect(route('security.api-tokens'));
    $response->assertSessionHas('error');
    expect($user->fresh()->tokens()->where('name', 'Root token')->exists())->toBeFalse();
});

it('revokes an owned api token', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    // createToken() reads session('currentTeam') directly; withSession() below only queues
    // data for the next HTTP request, so the session must be set for real before this call.
    session(['currentTeam' => $team]);
    $token = $user->createToken('Revoke me', ['read']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('security.api-tokens.destroy', ['id' => $token->accessToken->id]));

    $response->assertRedirect();
    expect($user->fresh()->tokens()->where('id', $token->accessToken->id)->exists())->toBeFalse();
});
