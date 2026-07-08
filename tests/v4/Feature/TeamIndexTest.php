<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the team index Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Acme Inc']);
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Team/Index')
        ->where('team.name', 'Acme Inc')
        ->where('updateUrl', route('team.update'))
    );
});

it('updates the team name and description', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('team.update'), [
            'name' => 'Renamed Team',
            'description' => 'A new description',
        ]);

    $response->assertRedirect();
    expect($team->fresh())
        ->name->toBe('Renamed Team')
        ->description->toBe('A new description');
});

it('blocks deletion of the last team a user belongs to', function () {
    // User::boot()'s static::created hook (app/Models/User.php) auto-creates and attaches
    // a personal team (role: owner) for every new user. So a freshly-factoried user already
    // has exactly one team - their own personal team - without creating anything else here.
    $user = User::factory()->create();
    $team = $user->teams()->first();
    // The auto-created personal team defaults show_boarding to true, which would otherwise
    // redirect this request to the onboarding flow before it ever reaches the team page.
    $team->update(['show_boarding' => false]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Team/Index')
        ->where('deletionBlockedReason', 'last-team')
    );
});
