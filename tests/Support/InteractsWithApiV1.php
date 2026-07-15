<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;

/**
 * Shared setup for tests hitting /api/v1/* routes. `is_api_enabled` defaults to
 * false at the DB level (migration 2024_09_26_083441_disable_api_by_default), so
 * every v1 request needs it explicitly enabled here, unlike the plain
 * InstanceSettings::forceCreate(['id' => 0]) every other v4 test uses.
 *
 * apiToken() encapsulates a real gotcha: User::createToken() reads
 * session('currentTeam')->id directly at call time, not from the request, so the
 * session must be set for real (not via withSession(), which only queues data for
 * the next HTTP request) before calling it. A write/root/write:sensitive-ability
 * token additionally needs the user's team-pivot role to be admin/owner, or
 * EnsureTokenBelongsToCurrentTeamMember rejects it with 403 regardless of the
 * Sanctum ability check.
 */
trait InteractsWithApiV1
{
    private function apiEnable(): void
    {
        InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    }

    /**
     * @param  array<int, string>  $abilities
     */
    private function apiToken(User $user, Team $team, array $abilities, string $role = 'admin'): string
    {
        $team->members()->attach($user, ['role' => $role]);
        session(['currentTeam' => $team]);

        return $user->createToken('test', $abilities)->plainTextToken;
    }

    /**
     * @return array<string, string>
     */
    private function apiHeaders(string $token): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];
    }
}
