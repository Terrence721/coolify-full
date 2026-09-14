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

it('renders the audit log page for an admin, scoped to the current team', function () {
    $admin = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($admin, ['role' => 'admin']);
    Server::factory()->create(['team_id' => $team->id, 'name' => 'my-server']);

    $response = $this->actingAs($admin)
        ->withSession(['currentTeam' => $team])
        ->get(route('team.audit-log'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Team/AuditLog')
        ->has('entries', 1)
        ->where('entries.0.event', 'created')
        ->where('entries.0.subjectType', 'Server')
    );
});

it('never shows another team\'s audit entries', function () {
    $admin = User::factory()->create();
    $myTeam = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $myTeam->members()->attach($admin, ['role' => 'admin']);

    Server::factory()->create(['team_id' => $myTeam->id, 'name' => 'my-server']);
    Server::factory()->create(['team_id' => $otherTeam->id, 'name' => 'other-teams-server']);

    $response = $this->actingAs($admin)
        ->withSession(['currentTeam' => $myTeam])
        ->get(route('team.audit-log'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Team/AuditLog')
        ->has('entries', 1)
    );
});

it('rejects a plain member with a 403, matching the manageMembers gate', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => 'member']);

    $response = $this->actingAs($member)
        ->withSession(['currentTeam' => $team])
        ->get(route('team.audit-log'));

    $response->assertForbidden();
});

it('logs a role change with the old and new role', function () {
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($admin, ['role' => 'admin']);
    $team->members()->attach($member, ['role' => 'member']);

    $this->actingAs($admin)
        ->withSession(['currentTeam' => $team])
        ->put(route('team.member.update-role', ['member_id' => $member->id]), ['role' => 'admin']);

    $response = $this->actingAs($admin)
        ->withSession(['currentTeam' => $team])
        ->get(route('team.audit-log'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('entries', 1)
        ->where('entries.0.event', 'member.role_updated')
    );
});

it('logs a member removal', function () {
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($admin, ['role' => 'admin']);
    $team->members()->attach($member, ['role' => 'member']);

    $this->actingAs($admin)
        ->withSession(['currentTeam' => $team])
        ->delete(route('team.member.remove', ['member_id' => $member->id]));

    $response = $this->actingAs($admin)
        ->withSession(['currentTeam' => $team])
        ->get(route('team.audit-log'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('entries', 1)
        ->where('entries.0.event', 'member.removed')
    );
});

it('logs a sent invitation', function () {
    $admin = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($admin, ['role' => 'admin']);

    $this->actingAs($admin)
        ->withSession(['currentTeam' => $team])
        ->post(route('team.invitation.send'), ['email' => 'invitee@example.com', 'role' => 'member', 'via' => 'link']);

    $response = $this->actingAs($admin)
        ->withSession(['currentTeam' => $team])
        ->get(route('team.audit-log'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('entries', 1)
        ->where('entries.0.event', 'member.invited')
    );
});
