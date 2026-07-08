<?php

declare(strict_types=1);

use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the settings oauth Inertia page', function () {
    $user = User::forceCreate(User::factory()->raw(['id' => 0]));
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    OauthSetting::create(['provider' => 'github']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('settings.oauth'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('SettingsOauth')
        ->where('providers.0.provider', 'github')
    );
});

it('updates oauth provider settings', function () {
    $user = User::forceCreate(User::factory()->raw(['id' => 0]));
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $github = OauthSetting::create(['provider' => 'github']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('settings.oauth.update'), [
            'providers' => [
                [
                    'id' => $github->id,
                    'provider' => 'github',
                    'enabled' => true,
                    'client_id' => 'client-id',
                    'client_secret' => 'client-secret',
                    'redirect_uri' => null,
                ],
            ],
        ]);

    $response->assertRedirect();
    $github->refresh();
    expect($github->enabled)->toBeTrue();
    expect($github->client_id)->toBe('client-id');
});

it('disables a provider left incomplete and reports an error', function () {
    $user = User::forceCreate(User::factory()->raw(['id' => 0]));
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $github = OauthSetting::create(['provider' => 'github']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('settings.oauth.update'), [
            'providers' => [
                [
                    'id' => $github->id,
                    'provider' => 'github',
                    'enabled' => true,
                    'client_id' => null,
                    'client_secret' => null,
                ],
            ],
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect($github->fresh()->enabled)->toBeFalse();
});
