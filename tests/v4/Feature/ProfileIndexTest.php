<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the profile index Inertia page', function () {
    $user = User::factory()->create(['name' => 'Jane Doe']);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('profile'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Profile/Index')
        ->where('name', 'Jane Doe')
        ->where('email', $user->email)
        ->where('showVerification', false)
    );
});

it('updates the profile name', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('profile.update'), ['name' => 'New Name']);

    $response->assertRedirect();
    expect($user->fresh()->name)->toBe('New Name');
});

it('updates the password', function () {
    $user = User::factory()->create(['password' => bcrypt('old-password')]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('profile.password.update'), [
            'current_password' => 'old-password',
            'new_password' => 'new-strong-password-123',
            'new_password_confirmation' => 'new-strong-password-123',
        ]);

    $response->assertRedirect();
    expect(\Illuminate\Support\Facades\Hash::check('new-strong-password-123', $user->fresh()->password))->toBeTrue();
});

it('rejects a password update with an incorrect current password', function () {
    $user = User::factory()->create(['password' => bcrypt('old-password')]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->from(route('profile'))
        ->put(route('profile.password.update'), [
            'current_password' => 'wrong-password',
            'new_password' => 'new-strong-password-123',
            'new_password_confirmation' => 'new-strong-password-123',
        ]);

    $response->assertRedirect(route('profile'));
    $response->assertSessionHas('error');
});
