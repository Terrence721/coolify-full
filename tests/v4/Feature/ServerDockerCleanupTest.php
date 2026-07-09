<?php

declare(strict_types=1);

use App\Jobs\DockerCleanupJob;
use App\Models\DockerCleanupExecution;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the server docker cleanup Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    DockerCleanupExecution::create([
        'server_id' => $server->id,
        'status' => 'success',
        'message' => "line one\nline two",
        'finished_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.docker-cleanup', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/DockerCleanup')
        ->has('serverNavbar')
        ->has('sidebar')
        ->has('settings')
        ->has('executions', 1)
        ->where('canUpdate', true)
    );
});

it('returns 404 for a server owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.docker-cleanup', ['server_uuid' => $server->uuid]));

    $response->assertNotFound();
});

it('updates docker cleanup settings', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('server.docker-cleanup.update', ['server_uuid' => $server->uuid]), [
            'dockerCleanupFrequency' => '0 0 * * *',
            'dockerCleanupThreshold' => 50,
            'forceDockerCleanup' => false,
            'deleteUnusedVolumes' => true,
            'deleteUnusedNetworks' => true,
            'disableApplicationImageRetention' => true,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Server updated.');
    $server->settings->refresh();
    expect($server->settings->docker_cleanup_frequency)->toBe('0 0 * * *');
    expect($server->settings->docker_cleanup_threshold)->toBe(50);
    expect($server->settings->delete_unused_volumes)->toBeTruthy();
});

it('rejects an invalid cron expression for docker cleanup frequency', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $originalFrequency = $server->settings->docker_cleanup_frequency;

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('server.docker-cleanup.update', ['server_uuid' => $server->uuid]), [
            'dockerCleanupFrequency' => 'not-a-cron-expression',
            'dockerCleanupThreshold' => 50,
            'forceDockerCleanup' => false,
            'deleteUnusedVolumes' => false,
            'deleteUnusedNetworks' => false,
            'disableApplicationImageRetention' => false,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Invalid Cron / Human expression for Docker Cleanup Frequency.');
    $server->settings->refresh();
    expect($server->settings->docker_cleanup_frequency)->toBe($originalFrequency);
});

it('dispatches a queued manual cleanup job without touching SSH synchronously', function () {
    Queue::fake();

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.docker-cleanup.manual-cleanup', ['server_uuid' => $server->uuid]));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Manual cleanup job started. Depending on the amount of data, this might take a while.');
    Queue::assertPushed(DockerCleanupJob::class);
});

it('returns executions as JSON for polling', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    DockerCleanupExecution::create([
        'server_id' => $server->id,
        'status' => 'running',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson(route('server.docker-cleanup.executions', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertJsonCount(1, 'executions');
    $response->assertJsonPath('executions.0.status', 'running');
});

it('downloads the log for an execution', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $execution = DockerCleanupExecution::create([
        'server_id' => $server->id,
        'status' => 'success',
        'message' => 'log contents here',
        'finished_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.docker-cleanup.download-log', ['server_uuid' => $server->uuid, 'execution' => $execution->id]));

    $response->assertOk();
    expect($response->streamedContent())->toBe('log contents here');
});
