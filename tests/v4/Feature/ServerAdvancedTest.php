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

it('renders the server advanced Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.advanced', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/Advanced')
        ->has('serverNavbar')
        ->has('sidebar')
        ->has('concurrentBuilds')
        ->has('updateUrl')
    );
});

it('updates advanced server settings', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('server.advanced.update', ['server_uuid' => $server->uuid]), [
            'serverDiskUsageCheckFrequency' => '0 23 * * *',
            'serverDiskUsageNotificationThreshold' => 75,
            'concurrentBuilds' => 3,
            'dynamicTimeout' => 600,
            'deploymentQueueLimit' => 10,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect($server->settings->fresh()->concurrent_builds)->toBe(3);
    expect($server->settings->fresh()->deployment_queue_limit)->toBe(10);
});

it('rejects an invalid disk usage check frequency cron expression', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('server.advanced.update', ['server_uuid' => $server->uuid]), [
            'serverDiskUsageCheckFrequency' => 'not-a-cron-expression',
            'serverDiskUsageNotificationThreshold' => 75,
            'concurrentBuilds' => 3,
            'dynamicTimeout' => 600,
            'deploymentQueueLimit' => 10,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect($server->settings->fresh()->concurrent_builds)->not->toBe(3);
});

it('returns 404 for a server owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.advanced', ['server_uuid' => $server->uuid]));

    $response->assertNotFound();
});
