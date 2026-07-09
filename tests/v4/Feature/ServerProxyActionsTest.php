<?php

declare(strict_types=1);

use App\Jobs\RestartProxyJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('dispatches a restart proxy job', function () {
    Bus::fake([RestartProxyJob::class]);
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.proxy-actions.restart', ['server_uuid' => $server->uuid]));

    $response->assertRedirect();
    Bus::assertDispatched(RestartProxyJob::class);
});

it('checks proxy status without error on a non-functional server', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_reachable' => false, 'is_usable' => false]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.proxy-actions.check-status', ['server_uuid' => $server->uuid]));

    $response->assertRedirect();
});

it('returns 404 for proxy actions on a server owned by another team', function (string $route) {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route($route, ['server_uuid' => $server->uuid]));

    $response->assertNotFound();
})->with([
    'server.proxy-actions.restart',
    'server.proxy-actions.stop',
    'server.proxy-actions.start',
    'server.proxy-actions.check-status',
]);
