<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    // DNS validation performs a real lookup against validateDNSEntry() - out of scope
    // here (same standing untested-network-touching-path convention as elsewhere in
    // this migration), so it's disabled for these tests.
    InstanceSettings::forceCreate(['id' => 0, 'is_dns_validation_enabled' => false]);
});

/**
 * Creating a User with id 0 auto-provisions and owns the "Root Team" (id 0) via
 * User::booted()'s created hook - that root-team membership is what isInstanceAdmin()
 * actually checks. Matches the SettingsBackupControllerTest convention.
 */
function makeSettingsIndexAdmin(): User
{
    $user = User::forceCreate(User::factory()->raw(['id' => 0]));
    Team::find(0)->update(['show_boarding' => false]);

    return $user;
}

it('redirects to the dashboard for a non instance-admin user', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('settings.index'));

    $response->assertRedirect(route('dashboard'));
});

it('renders the settings index page', function () {
    $user = makeSettingsIndexAdmin();
    Server::factory()->create(['id' => 0, 'team_id' => 0]);
    InstanceSettings::get()->update(['fqdn' => 'https://coolify.example.com', 'instance_name' => 'My Coolify']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => Team::find(0)])
        ->get(route('settings.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Settings/Index')
        ->where('settings.fqdn', 'https://coolify.example.com')
        ->where('settings.instance_name', 'My Coolify')
        ->where('isDev', false)
        ->where('hasServer', true)
        ->has('timezones')
    );
});

it('updates instance settings', function () {
    $user = makeSettingsIndexAdmin();
    Server::factory()->create(['id' => 0, 'team_id' => 0]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => Team::find(0)])
        ->put(route('settings.update'), [
            'fqdn' => 'https://coolify.example.com',
            'instance_name' => 'My Coolify',
            'public_ipv4' => '1.2.3.4',
            'public_ipv6' => null,
            'instance_timezone' => 'America/New_York',
            'dev_helper_version' => null,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Instance settings updated successfully!');
    $settings = InstanceSettings::get();
    expect($settings->fqdn)->toBe('https://coolify.example.com');
    expect($settings->instance_name)->toBe('My Coolify');
    expect($settings->public_ipv4)->toBe('1.2.3.4');
    expect($settings->instance_timezone)->toBe('America/New_York');
});

it('rejects an invalid timezone', function () {
    $user = makeSettingsIndexAdmin();
    Server::factory()->create(['id' => 0, 'team_id' => 0]);
    InstanceSettings::get()->update(['instance_timezone' => 'UTC']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => Team::find(0)])
        ->from(route('settings.index'))
        ->put(route('settings.update'), [
            'fqdn' => null,
            'instance_name' => null,
            'public_ipv4' => null,
            'public_ipv6' => null,
            'instance_timezone' => 'Not/A_Real_Zone',
            'dev_helper_version' => null,
        ]);

    $response->assertRedirect(route('settings.index'));
    $response->assertSessionHasErrors('instance_timezone');
    expect(InstanceSettings::get()->instance_timezone)->toBe('UTC');
});

it('flags a domain conflict instead of saving', function () {
    $user = makeSettingsIndexAdmin();
    Server::factory()->create(['id' => 0, 'team_id' => 0]);
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    Application::factory()->create([
        'environment_id' => $environment->id,
        'fqdn' => 'https://taken.example.com',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => Team::find(0)])
        ->put(route('settings.update'), [
            'fqdn' => 'https://taken.example.com',
            'instance_name' => null,
            'public_ipv4' => null,
            'public_ipv6' => null,
            'instance_timezone' => 'UTC',
            'dev_helper_version' => null,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('showDomainConflictModal', true);
    $response->assertSessionHas('domainConflicts');
    expect(InstanceSettings::get()->fqdn)->not->toBe('https://taken.example.com');

    $followUp = $this->actingAs($user)
        ->withSession(['currentTeam' => Team::find(0)])
        ->get(route('settings.index'));
    $followUp->assertInertia(fn (Assert $page) => $page
        ->where('flash.showDomainConflictModal', true)
        ->has('flash.domainConflicts', 1)
    );
});

it('saves a conflicting domain when force_save_domains is set', function () {
    $user = makeSettingsIndexAdmin();
    Server::factory()->create(['id' => 0, 'team_id' => 0]);
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    Application::factory()->create([
        'environment_id' => $environment->id,
        'fqdn' => 'https://taken.example.com',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => Team::find(0)])
        ->put(route('settings.update'), [
            'fqdn' => 'https://taken.example.com',
            'instance_name' => null,
            'public_ipv4' => null,
            'public_ipv6' => null,
            'instance_timezone' => 'UTC',
            'dev_helper_version' => null,
            'force_save_domains' => true,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Instance settings updated successfully!');
    expect(InstanceSettings::get()->fqdn)->toBe('https://taken.example.com');
});

it('refuses to build the helper image outside of development mode', function () {
    $user = makeSettingsIndexAdmin();
    Server::factory()->create(['id' => 0, 'team_id' => 0]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => Team::find(0)])
        ->post(route('settings.build-helper-image'), ['dev_helper_version' => null]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Building helper image is only available in development mode.');
});

it('redirects build-helper-image to the dashboard for a non instance-admin user', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('settings.build-helper-image'), ['dev_helper_version' => null]);

    $response->assertRedirect(route('dashboard'));
});
