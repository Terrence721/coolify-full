<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the team member index Inertia page', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => 'owner']);
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => 'member']);

    $response = $this->actingAs($owner)
        ->withSession(['currentTeam' => $team])
        ->get(route('team.member.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Team/Member/Index')
        ->has('members', 2)
        ->where('currentUserRole', 'owner')
        ->where('canManageMembers', true)
        ->where('canManageInvitations', true)
    );
});

it('promotes a member to admin', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => 'owner']);
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => 'member']);

    $response = $this->actingAs($owner)
        ->withSession(['currentTeam' => $team])
        ->put(route('team.member.update-role', ['member_id' => $member->id]), [
            'role' => 'admin',
        ]);

    $response->assertRedirect();
    expect($member->teams()->where('team_id', $team->id)->first()->pivot->role)->toBe('admin');
});

it('refuses to let an admin promote another admin to owner', function () {
    $adminActor = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($adminActor, ['role' => 'admin']);
    $otherAdmin = User::factory()->create();
    $team->members()->attach($otherAdmin, ['role' => 'admin']);

    $response = $this->actingAs($adminActor)
        ->withSession(['currentTeam' => $team])
        ->put(route('team.member.update-role', ['member_id' => $otherAdmin->id]), [
            'role' => 'owner',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'You are not authorized to perform this action.');
    expect($otherAdmin->teams()->where('team_id', $team->id)->first()->pivot->role)->toBe('admin');
});

it('removes a member from the team', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => 'owner']);
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => 'member']);

    $response = $this->actingAs($owner)
        ->withSession(['currentTeam' => $team])
        ->delete(route('team.member.remove', ['member_id' => $member->id]));

    $response->assertRedirect();
    expect($member->teams()->where('team_id', $team->id)->exists())->toBeFalse();
});

it('generates an invitation link without sending an email', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => 'owner']);

    $response = $this->actingAs($owner)
        ->withSession(['currentTeam' => $team])
        ->post(route('team.invitation.send'), [
            'email' => 'invitee@example.com',
            'role' => 'member',
            'via' => 'link',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Invitation link generated.');
    expect(TeamInvitation::where('email', 'invitee@example.com')->where('team_id', $team->id)->exists())->toBeTrue();
});

it('rejects an admin inviting an owner', function () {
    // A plain "member" can't reach this business rule at all — the manageInvitations
    // policy gate already blocks members from the invitation endpoint entirely (403),
    // since only admins/owners can manage invitations in the first place. The
    // "admin inviting an owner" case is the actual reachable privilege-escalation
    // guard this action enforces past that gate.
    $adminActor = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($adminActor, ['role' => 'admin']);

    $response = $this->actingAs($adminActor)
        ->withSession(['currentTeam' => $team])
        ->post(route('team.invitation.send'), [
            'email' => 'invitee@example.com',
            'role' => 'owner',
            'via' => 'link',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Admins cannot invite owners.');
    expect(TeamInvitation::where('email', 'invitee@example.com')->exists())->toBeFalse();
});

it('forbids a plain member from reaching the invitation endpoint', function () {
    $memberActor = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($memberActor, ['role' => 'member']);

    $response = $this->actingAs($memberActor)
        ->withSession(['currentTeam' => $team])
        ->post(route('team.invitation.send'), [
            'email' => 'invitee@example.com',
            'role' => 'member',
            'via' => 'link',
        ]);

    $response->assertForbidden();
});

it('revokes a pending invitation', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => 'owner']);
    $invitation = TeamInvitation::create([
        'team_id' => $team->id,
        'uuid' => (string) new Cuid2,
        'email' => 'invitee@example.com',
        'role' => 'member',
        'link' => 'https://example.com/invite/abc',
        'via' => 'link',
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['currentTeam' => $team])
        ->delete(route('team.invitation.destroy', ['invitation_id' => $invitation->id]));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Invitation revoked.');
    expect(TeamInvitation::find($invitation->id))->toBeNull();
});
