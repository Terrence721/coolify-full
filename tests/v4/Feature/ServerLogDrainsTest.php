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
