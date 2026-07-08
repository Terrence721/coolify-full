<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// verifyPasswordConfirmation() -> shouldSkipPasswordConfirmation() reads the InstanceSettings
// singleton row (id 0), same gotcha documented elsewhere in this migration.
beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('forbids access for a non-instance-admin user', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('team.admin-view'));

    $response->assertRedirect(route('dashboard'));
});

it('renders the team admin view Inertia page for an instance admin', function () {
    $rootUser = User::forceCreate(User::factory()->raw(['id' => 0]));
    $rootTeam = $rootUser->teams()->first();
    $rootTeam->update(['show_boarding' => false]);
    User::factory()->create(['name' => 'Findable Person']);

    $response = $this->actingAs($rootUser)
        ->withSession(['currentTeam' => $rootTeam])
        ->get(route('team.admin-view'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Team/AdminView')
        ->where('deleteUserUrl', route('team.admin-view.delete-user'))
        ->where('users.0.name', 'Findable Person')
    );
});

it('filters users by the search query', function () {
    $rootUser = User::forceCreate(User::factory()->raw(['id' => 0]));
    $rootTeam = $rootUser->teams()->first();
    $rootTeam->update(['show_boarding' => false]);
    User::factory()->create(['name' => 'Findable Person']);
    User::factory()->create(['name' => 'Someone Else']);

    $response = $this->actingAs($rootUser)
        ->withSession(['currentTeam' => $rootTeam])
        ->get(route('team.admin-view', ['search' => 'Findable']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Team/AdminView')
        ->has('users', 1)
        ->where('users.0.name', 'Findable Person')
    );
});

it('deletes a user with a correct password', function () {
    $rootUser = User::forceCreate(User::factory()->raw(['id' => 0, 'password' => bcrypt('secret-password')]));
    $rootTeam = $rootUser->teams()->first();
    $rootTeam->update(['show_boarding' => false]);
    $otherUser = User::factory()->create();

    $response = $this->actingAs($rootUser)
        ->withSession(['currentTeam' => $rootTeam])
        ->delete(route('team.admin-view.delete-user'), [
            'id' => $otherUser->id,
            'password' => 'secret-password',
        ]);

    $response->assertRedirect();
    expect(User::find($otherUser->id))->toBeNull();
});

it('rejects deleting a user with an incorrect password', function () {
    $rootUser = User::forceCreate(User::factory()->raw(['id' => 0, 'password' => bcrypt('secret-password')]));
    $rootTeam = $rootUser->teams()->first();
    $rootTeam->update(['show_boarding' => false]);
    $otherUser = User::factory()->create();

    $response = $this->actingAs($rootUser)
        ->withSession(['currentTeam' => $rootTeam])
        ->delete(route('team.admin-view.delete-user'), [
            'id' => $otherUser->id,
            'password' => 'wrong-password',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect(User::find($otherUser->id))->not->toBeNull();
});
