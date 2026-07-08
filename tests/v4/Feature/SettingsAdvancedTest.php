<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the settings advanced Inertia page', function () {
    $user = User::forceCreate(User::factory()->raw(['id' => 0]));
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('settings.advanced'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Settings/Advanced')
        ->has('settings.is_api_enabled')
    );
});

it('updates advanced settings including allowed ips normalization', function () {
    $user = User::forceCreate(User::factory()->raw(['id' => 0]));
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('settings.advanced.update'), [
            'is_api_enabled' => true,
            'allowed_ips' => '192.168.1.100, 10.0.0.0/8',
            'custom_dns_servers' => '8.8.8.8, 1.1.1.1',
        ]);

    $response->assertRedirect();
    $settings = InstanceSettings::get();
    // is_api_enabled/is_registration_enabled aren't in InstanceSettings::$casts, so SQLite
    // returns them as raw ints (0/1) rather than real booleans - toBeTruthy() rather than
    // toBeTrue() to match, same gotcha as other uncast boolean columns on this model.
    expect($settings->is_api_enabled)->toBeTruthy();
    expect($settings->allowed_ips)->toBe('192.168.1.100,10.0.0.0/8');
    expect($settings->custom_dns_servers)->toBe('8.8.8.8,1.1.1.1');
});

it('rejects invalid allowed ip entries', function () {
    $user = User::forceCreate(User::factory()->raw(['id' => 0]));
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->from(route('settings.advanced'))
        ->put(route('settings.advanced.update'), [
            'allowed_ips' => 'not-an-ip',
        ]);

    // The ValidIpOrCidr rule rejects this at the Validator layer (standard validation-error
    // redirect), before the controller's own post-validation normalization/error-flash logic
    // ever runs.
    $response->assertRedirect(route('settings.advanced'));
    $response->assertSessionHasErrors('allowed_ips');
});

it('enables registration with a correct password', function () {
    // is_registration_enabled defaults to true in the schema, so start from false to make
    // this test meaningful.
    InstanceSettings::get()->update(['is_registration_enabled' => false]);
    $user = User::forceCreate(User::factory()->raw(['id' => 0, 'password' => bcrypt('secret-password')]));
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('settings.advanced.enable-registration'), ['password' => 'secret-password']);

    $response->assertRedirect();
    expect(InstanceSettings::get()->is_registration_enabled)->toBeTruthy();
});

it('rejects enabling registration with an incorrect password', function () {
    InstanceSettings::get()->update(['is_registration_enabled' => false]);
    $user = User::forceCreate(User::factory()->raw(['id' => 0, 'password' => bcrypt('secret-password')]));
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('settings.advanced.enable-registration'), ['password' => 'wrong-password']);

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect(InstanceSettings::get()->is_registration_enabled)->toBeFalsy();
});
