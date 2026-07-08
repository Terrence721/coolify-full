<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the force password reset Inertia page when a reset is required', function () {
    $user = User::factory()->create(['force_password_reset' => true]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('auth.force-password-reset'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('ForcePasswordReset')
        ->where('email', $user->email)
    );
});

it('redirects to the dashboard when a reset is not required', function () {
    $user = User::factory()->create(['force_password_reset' => false]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('auth.force-password-reset'));

    $response->assertRedirect(route('dashboard'));
});

it('resets the password and clears the force_password_reset flag', function () {
    $user = User::factory()->create(['force_password_reset' => true]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('auth.force-password-reset.update'), [
            'password' => 'a-brand-new-strong-password-123',
            'password_confirmation' => 'a-brand-new-strong-password-123',
        ]);

    $response->assertRedirect(route('dashboard'));
    $user->refresh();
    expect($user->force_password_reset)->toBeFalse();
    expect(Hash::check('a-brand-new-strong-password-123', $user->password))->toBeTrue();
});
