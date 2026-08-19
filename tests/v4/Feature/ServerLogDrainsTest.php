<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the server log drains Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.log-drains', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/LogDrains')
        ->has('serverNavbar')
        ->has('sidebar')
        ->where('canUpdate', true)
        ->where('isLogDrainNewRelicEnabled', false)
        ->where('isLogDrainAxiomEnabled', false)
        ->where('isLogDrainCustomEnabled', false)
    );
});

it('saves new relic settings without enabling', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.log-drains.submit', ['server_uuid' => $server->uuid]), [
            'type' => 'newrelic',
            'logDrainNewRelicLicenseKey' => 'abc123',
            'logDrainNewRelicBaseUri' => 'https://log-api.eu.newrelic.com/log/v1',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect($server->settings->fresh()->logdrain_newrelic_license_key)->toBe('abc123');
    expect($server->settings->fresh()->is_logdrain_newrelic_enabled)->toBeFalsy();
});

it('rejects invalid new relic settings', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.log-drains.submit', ['server_uuid' => $server->uuid]), [
            'type' => 'newrelic',
            'logDrainNewRelicLicenseKey' => 'abc123',
            'logDrainNewRelicBaseUri' => 'not-a-url',
        ]);

    $response->assertSessionHasErrors('logDrainNewRelicBaseUri');
});

it('saves axiom settings without enabling', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.log-drains.submit', ['server_uuid' => $server->uuid]), [
            'type' => 'axiom',
            'logDrainAxiomDatasetName' => 'my-dataset',
            'logDrainAxiomApiKey' => 'xoxb-token',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect($server->settings->fresh()->logdrain_axiom_dataset_name)->toBe('my-dataset');
});

it('saves custom fluentbit settings without enabling', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.log-drains.submit', ['server_uuid' => $server->uuid]), [
            'type' => 'custom',
            'logDrainCustomConfig' => '[OUTPUT]\n    Name  stdout',
            'logDrainCustomConfigParser' => null,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect($server->settings->fresh()->logdrain_custom_config)->not->toBeNull();
});

it('rejects enabling a log drain provider with missing required fields', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.log-drains.toggle', ['server_uuid' => $server->uuid]), [
            'type' => 'newrelic',
            'enabled' => true,
        ]);

    $response->assertSessionHasErrors('logDrainNewRelicLicenseKey');
    expect($server->settings->fresh()->is_logdrain_newrelic_enabled)->toBeFalsy();
});

it('returns 404 for a server owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.log-drains', ['server_uuid' => $server->uuid]));

    $response->assertNotFound();
});

// A plain team member can load this page (ServerPolicy::view is membership-only, and index()
// has no authorize() call), but the log-drain credentials it renders are the exact same five
// fields the API deliberately gates behind the `read:sensitive` token ability in
// ServersController::removeSensitiveDataFromSettings(). Handing them to any member through the
// web UI contradicted the API's own answer for an identical field set. Members still see the
// page and the enabled/disabled state - only the secret values are withheld.
it('withholds log drain credentials from a plain member', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'member']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update([
        'logdrain_newrelic_license_key' => 'SECRET-NEWRELIC-LICENSE-KEY',
        'logdrain_axiom_api_key' => 'SECRET-AXIOM-API-KEY',
        'logdrain_custom_config' => 'SECRET-CUSTOM-CONFIG',
        'logdrain_custom_config_parser' => 'SECRET-CUSTOM-PARSER',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.log-drains', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->where('canUpdate', false));
    expect($response->getContent())->not->toContain('SECRET-NEWRELIC-LICENSE-KEY')
        ->and($response->getContent())->not->toContain('SECRET-AXIOM-API-KEY')
        ->and($response->getContent())->not->toContain('SECRET-CUSTOM-CONFIG')
        ->and($response->getContent())->not->toContain('SECRET-CUSTOM-PARSER');
});

it('still shows log drain credentials to an admin', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['logdrain_axiom_api_key' => 'SECRET-AXIOM-API-KEY']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.log-drains', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('logDrainAxiomApiKey', 'SECRET-AXIOM-API-KEY')
    );
});
