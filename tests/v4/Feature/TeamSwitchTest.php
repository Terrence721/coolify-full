<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('switches the session to another team the user belongs to', function () {
    $user = User::factory()->create();
    $teamA = Team::factory()->create(['name' => 'Team A']);
    $teamB = Team::factory()->create(['name' => 'Team B']);
    $teamA->members()->attach($user, ['role' => 'admin']);
    $teamB->members()->attach($user, ['role' => 'member']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $teamA])
        ->post(route('team.switch'), ['team_id' => $teamB->id]);

    $response->assertRedirect(route('dashboard'));
    expect(session('currentTeam')->id)->toBe($teamB->id);
});

// The critical case: switching must be scoped to the user's own teams() relation, not a raw
// Team::find() - otherwise posting an arbitrary team_id would let any authenticated user move
// their session onto a team they were never invited to, seeing its projects/servers/resources.
it('refuses to switch to a team the user does not belong to', function () {
    $user = User::factory()->create();
    $ownTeam = Team::factory()->create();
    $otherTeam = Team::factory()->create(['name' => 'Not My Team']);
    $ownTeam->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $ownTeam])
        ->post(route('team.switch'), ['team_id' => $otherTeam->id]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();
    expect(session('currentTeam')->id)->toBe($ownTeam->id);
});

it('requires a team_id', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('team.switch'), []);

    $response->assertSessionHasErrors('team_id');
});

it('exposes every team the user belongs to as availableTeams on every Inertia page', function () {
    // User::factory()->create() auto-creates a personal team (User::booted()'s `created` hook),
    // so the real team count here is that personal team plus the 2 attached below - asserted
    // dynamically rather than hard-coded, so this test doesn't silently drift if that setup
    // behavior ever changes.
    $user = User::factory()->create();
    $teamA = Team::factory()->create(['name' => 'Team A']);
    $teamB = Team::factory()->create(['name' => 'Team B']);
    $teamA->members()->attach($user, ['role' => 'admin']);
    $teamB->members()->attach($user, ['role' => 'member']);
    $expectedTeamCount = $user->teams()->count();

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $teamA])
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('availableTeams', $expectedTeamCount)
        ->where('availableTeams', fn ($teams) => collect($teams)->pluck('name')->contains('Team A')
            && collect($teams)->pluck('name')->contains('Team B')
        )
    );
});
