<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// isInstanceAdmin() requires membership (admin/owner role) in the root team (id 0).
// User::boot()'s static::created hook auto-creates that root team + owner membership when
// the user itself is created with id 0 (see AdminIndexTest for the same fixture pattern).
// instanceSettings() reads the id-0 InstanceSettings singleton row, seeded at install time
// in real deployments but not by any migration/factory here.
beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the settings updates Inertia page', function () {
    $user = User::forceCreate(User::factory()->raw(['id' => 0]));
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('settings.updates'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Settings/Updates')
        ->has('updateCheckFrequency')
    );
});

it('forbids access for a non-instance-admin user', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('settings.updates'));

    $response->assertRedirect(route('dashboard'));
});

it('updates the update settings', function () {
    // Skip the self-hosted-only proxy reconfiguration branch (Server::findOrFail(0)->
    // setupDynamicProxyConfiguration()) - it targets the singleton "localhost" server that
    // isn't part of this test's fixture and isn't what this test is verifying.
    config(['constants.coolify.self_hosted' => false]);

    $user = User::forceCreate(User::factory()->raw(['id' => 0]));
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('settings.updates.update'), [
            'update_check_frequency' => '0 * * * *',
            'auto_update_frequency' => '0 0 * * *',
            'is_auto_update_enabled' => true,
        ]);

    $response->assertRedirect();
    expect(InstanceSettings::get())
        ->update_check_frequency->toBe('0 * * * *')
        ->is_auto_update_enabled->toBeTrue();
});
