<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// isCloud()/isDev() gate the whole page: real deployments allow it in the cloud product
// or in local dev. Tests run under APP_ENV=testing, so isDev() is forced to 'local' here
// to exercise the page at all — matching how the Livewire component required either flag.
beforeEach(function () {
    config(['app.env' => 'local']);
    // InstanceSettings::get() reads a singleton row hardcoded to id 0, seeded at install
    // time in real deployments (see NotificationsEmailTest for the same gotcha). Exception
    // reporting code paths touch this even on a plain abort(403), so every test here needs it.
    InstanceSettings::forceCreate(['id' => 0]);
});

it('forbids access for a non-root, non-impersonating user', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('admin.index'));

    $response->assertForbidden();
});

it('renders the admin dashboard for the root user', function () {
    // Admin access is gated on Auth::id() === 0 specifically (the root user), not a role.
    $user = User::forceCreate(User::factory()->raw(['id' => 0]));
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('admin.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Index')
        ->where('name', $user->name)
    );
});

it('finds users matching a search term for the root user', function () {
    $user = User::forceCreate(User::factory()->raw(['id' => 0]));
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $match = User::factory()->create(['name' => 'Findable Person']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('admin.index', ['search' => 'Findable']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Index')
        ->has('foundUsers', 1)
        ->where('foundUsers.0.id', $match->id)
    );
});
