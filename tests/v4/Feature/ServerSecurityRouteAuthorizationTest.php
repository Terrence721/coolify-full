<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

/**
 * Regression coverage for a real bug found via an independent /code-review 113 pass (pseudo
 * peer review) on already-merged PR #113: CanUpdateResource's server_uuid branch only checked
 * auth()->user()->isAdmin() - admin status in the caller's *session-current* team - never
 * verifying the requested server_uuid actually belongs to a team the user administers, unlike
 * every other branch, which resolves the target model and runs it through Gate::allows('update',
 * $resource). Currently masked everywhere else behind this middleware because both real
 * call sites (ServerSecurityPatchesController, ServerSecurityTerminalAccessController)
 * independently re-scope via Server::ownedByCurrentTeam() before their own authorize() call -
 * but the bare `server.security` redirect closure has no such independent check, so it's the
 * one route where the gap is directly reachable.
 */
it('forbids an admin of a different team from passing the server_uuid branch via isAdmin() alone', function () {
    $adminTeam = Team::factory()->create();
    $user = User::factory()->create();
    $adminTeam->members()->attach($user, ['role' => 'admin']);
    test()->actingAs($user)->withSession(['currentTeam' => $adminTeam]);

    $otherTeam = Team::factory()->create();
    $targetServer = Server::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->get(route('server.security', ['server_uuid' => $targetServer->uuid]));

    $response->assertForbidden();
});

it('allows an admin of the server\'s own team through the server_uuid branch', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);

    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->get(route('server.security', ['server_uuid' => $server->uuid]));

    $response->assertRedirect(route('dashboard'));
});
