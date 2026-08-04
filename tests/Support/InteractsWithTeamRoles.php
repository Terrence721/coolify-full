<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Team;
use App\Models\User;

/**
 * User::isAdmin()/isOwner() resolve the role via role() -> currentTeam() -> session
 * ('currentTeam') when the user wasn't fetched with its pivot already loaded - exactly how
 * the real controllers reach a Policy (Gate::authorize() resolves the plain Auth::user()
 * instance, not a pivot-loaded one). Setting the session directly here mirrors that real path
 * instead of shortcutting through the pivot.
 */
trait InteractsWithTeamRoles
{
    private function memberOf(Team $team): User
    {
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => 'member']);
        session(['currentTeam' => $team]);

        return $user;
    }

    private function adminOf(Team $team): User
    {
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => 'admin']);
        session(['currentTeam' => $team]);

        return $user;
    }

    private function ownerOf(Team $team): User
    {
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => 'owner']);
        session(['currentTeam' => $team]);

        return $user;
    }

    /**
     * A user who is admin of their own current session team, but only a plain member of a
     * different team - the real shape needed to exercise the isAdmin()-checks-the-session-team,
     * not-the-target-team bug class. A user constructed via adminOf($otherTeam) alone, with no
     * membership in $memberOfTeam at all, only ever fails the separate teams->contains() check -
     * it never actually reaches the isAdmin() logic being tested.
     */
    private function adminOfButMemberOf(Team $sessionTeam, Team $memberOfTeam): User
    {
        $user = User::factory()->create();
        $sessionTeam->members()->attach($user, ['role' => 'admin']);
        $memberOfTeam->members()->attach($user, ['role' => 'member']);
        session(['currentTeam' => $sessionTeam]);

        return $user;
    }
}
