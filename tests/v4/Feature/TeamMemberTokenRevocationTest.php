<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;

// Regression coverage for issue #294: RevokeUserTeamTokens is the only thing standing between a
// removed/demoted team member and continued API access via a still-live personal access token.
// createToken() stamps team_id from session('currentTeam') at creation time, not from the token
// owner, so tests here must switch the session team before creating each token (same gotcha
// InteractsWithApiV1::apiToken() documents).

uses(RefreshDatabase::class);

beforeEach(function () {
    // The authorization-denied path renders a 403 error page, which shares InstanceSettings
    // for its layout - InstanceSettings::get() hard-fails via findOrFail(0) without this row.
    InstanceSettings::forceCreate(['id' => 0]);
});

function makeTeamWithAdminAndMember(): array
{
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($admin, ['role' => 'admin']);
    $team->members()->attach($member, ['role' => 'member']);

    return [$team, $admin, $member];
}

function createTeamScopedToken(User $user, Team $team): int
{
    session(['currentTeam' => $team]);

    return $user->createToken('test-token', ['read'])->accessToken->id;
}

it('revokes only the removed member\'s tokens scoped to that team, not their tokens in other teams', function () {
    [$team, $admin, $member] = makeTeamWithAdminAndMember();
    $otherTeam = Team::factory()->create();
    $member->teams()->attach($otherTeam, ['role' => 'member']);

    $teamTokenId = createTeamScopedToken($member, $team);
    $otherTeamTokenId = createTeamScopedToken($member, $otherTeam);

    session(['currentTeam' => $team]);
    $this->actingAs($admin)->delete(route('team.member.remove', ['member_id' => $member->id]));

    expect(PersonalAccessToken::find($teamTokenId))->toBeNull();
    expect(PersonalAccessToken::find($otherTeamTokenId))->not->toBeNull();
    expect($member->fresh()->teams->contains('id', $team->id))->toBeFalse();
});

it('leaves the admin\'s own tokens untouched when removing a different member', function () {
    [$team, $admin, $member] = makeTeamWithAdminAndMember();
    $adminTokenId = createTeamScopedToken($admin, $team);

    session(['currentTeam' => $team]);
    $this->actingAs($admin)->delete(route('team.member.remove', ['member_id' => $member->id]));

    expect(PersonalAccessToken::find($adminTokenId))->not->toBeNull();
});

it('does not revoke tokens or remove the member when the acting user lacks manageMembers authorization', function () {
    [$team, , $member] = makeTeamWithAdminAndMember();
    $otherMember = User::factory()->create();
    $team->members()->attach($otherMember, ['role' => 'member']);
    $memberTokenId = createTeamScopedToken($member, $team);

    session(['currentTeam' => $team]);
    $this->actingAs($otherMember)->delete(route('team.member.remove', ['member_id' => $member->id]));

    expect(PersonalAccessToken::find($memberTokenId))->not->toBeNull();
    expect($member->fresh()->teams->contains('id', $team->id))->toBeTrue();
});

it('revokes a member\'s team-scoped tokens when their role changes, forcing re-authentication', function () {
    [$team, $admin, $member] = makeTeamWithAdminAndMember();
    $otherTeam = Team::factory()->create();
    $member->teams()->attach($otherTeam, ['role' => 'member']);

    $teamTokenId = createTeamScopedToken($member, $team);
    $otherTeamTokenId = createTeamScopedToken($member, $otherTeam);

    session(['currentTeam' => $team]);
    $this->actingAs($admin)->put(route('team.member.update-role', ['member_id' => $member->id]), [
        'role' => 'admin',
    ]);

    expect(PersonalAccessToken::find($teamTokenId))->toBeNull();
    expect(PersonalAccessToken::find($otherTeamTokenId))->not->toBeNull();
    expect($member->fresh()->teams->where('id', $team->id)->first()?->pivot->role)->toBe('admin');
});

it('does not revoke tokens or change role when the acting user lacks manageMembers authorization', function () {
    [$team, , $member] = makeTeamWithAdminAndMember();
    $otherMember = User::factory()->create();
    $team->members()->attach($otherMember, ['role' => 'member']);
    $memberTokenId = createTeamScopedToken($member, $team);

    session(['currentTeam' => $team]);
    $this->actingAs($otherMember)->put(route('team.member.update-role', ['member_id' => $member->id]), [
        'role' => 'admin',
    ]);

    expect(PersonalAccessToken::find($memberTokenId))->not->toBeNull();
    expect($member->fresh()->teams->where('id', $team->id)->first()?->pivot->role)->toBe('member');
});
