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
}
