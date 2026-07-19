<?php

declare(strict_types=1);

use App\Jobs\ServerLimitCheckJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('does not disable any servers when the team has no custom server limit set', function () {
    // Regression test: handle() used to read $this->team->limits, a property that
    // doesn't exist on Team (the real column is custom_server_limit). Eloquent's magic
    // __get() silently returns null for an undefined property, and null is treated as 0
    // in the subtraction that followed - so every team with at least one server would
    // have had all of them force-disabled on every run, regardless of any actual limit.
    $team = Team::factory()->create(['custom_server_limit' => null]);
    $servers = Server::factory()->count(3)->create(['team_id' => $team->id]);

    (new ServerLimitCheckJob($team))->handle();

    foreach ($servers as $server) {
        expect($server->fresh()->isForceDisabled())->toBeFalse();
    }
});

it('disables only the newest servers over a set custom server limit', function () {
    $team = Team::factory()->create(['custom_server_limit' => 2]);
    $oldestServer = Server::factory()->create(['team_id' => $team->id, 'created_at' => now()->subDays(2)]);
    $middleServer = Server::factory()->create(['team_id' => $team->id, 'created_at' => now()->subDay()]);
    $newestServer = Server::factory()->create(['team_id' => $team->id, 'created_at' => now()]);

    (new ServerLimitCheckJob($team))->handle();

    expect($oldestServer->fresh()->isForceDisabled())->toBeFalse();
    expect($middleServer->fresh()->isForceDisabled())->toBeFalse();
    expect($newestServer->fresh()->isForceDisabled())->toBeTrue();
});

it('re-enables previously force-disabled servers once back under the limit', function () {
    $team = Team::factory()->create(['custom_server_limit' => 5]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->forceDisableServer();

    (new ServerLimitCheckJob($team))->handle();

    expect($server->fresh()->isForceDisabled())->toBeFalse();
});
