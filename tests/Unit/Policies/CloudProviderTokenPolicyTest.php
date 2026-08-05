<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\CloudProviderToken;
use App\Models\Team;
use App\Policies\CloudProviderTokenPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithTeamRoles;
use Tests\TestCase;

/**
 * Defense-in-depth hardening, not a live-exploitable fix: every real call site
 * (SecurityCloudTokensController::validateToken()/destroy()) pre-fetches the token via
 * CloudProviderToken::ownedByCurrentTeam()->findOrFail($id) before authorize() ever runs, so a
 * cross-team model can never actually reach this policy today. But view()/update()/delete()/
 * restore()/forceDelete() all checked only $user->isAdmin() - the session-current-team's role,
 * per User::role() -> currentTeam() -> session('currentTeam') - never comparing against
 * $cloudProviderToken->team_id at all, unlike every sibling policy (ServerPolicy, PrivateKeyPolicy).
 * Landmine for any future caller that doesn't happen to pre-scope the same way.
 */
class CloudProviderTokenPolicyTest extends TestCase
{
    use InteractsWithTeamRoles, RefreshDatabase;

    #[Test]
    public function denies_an_admin_of_a_different_team_from_viewing_or_managing_the_token(): void
    {
        $sessionTeam = Team::factory()->create();
        $tokenTeam = Team::factory()->create();
        $user = $this->adminOfButMemberOf($sessionTeam, $tokenTeam);
        $token = CloudProviderToken::create([
            'team_id' => $tokenTeam->id,
            'provider' => 'digitalocean',
            'token' => 'secret',
            'name' => 'token',
        ]);
        $policy = new CloudProviderTokenPolicy;

        $this->assertFalse($policy->view($user, $token));
        $this->assertFalse($policy->update($user, $token));
        $this->assertFalse($policy->delete($user, $token));
        $this->assertFalse($policy->restore($user, $token));
        $this->assertFalse($policy->forceDelete($user, $token));
    }

    #[Test]
    public function allows_an_admin_of_the_token_owning_team(): void
    {
        $team = Team::factory()->create();
        $user = $this->adminOf($team);
        $token = CloudProviderToken::create([
            'team_id' => $team->id,
            'provider' => 'digitalocean',
            'token' => 'secret',
            'name' => 'token',
        ]);
        $policy = new CloudProviderTokenPolicy;

        $this->assertTrue($policy->view($user, $token));
        $this->assertTrue($policy->update($user, $token));
        $this->assertTrue($policy->delete($user, $token));
    }

    #[Test]
    public function denies_a_plain_member_of_the_token_owning_team(): void
    {
        $team = Team::factory()->create();
        $user = $this->memberOf($team);
        $token = CloudProviderToken::create([
            'team_id' => $team->id,
            'provider' => 'digitalocean',
            'token' => 'secret',
            'name' => 'token',
        ]);

        $this->assertFalse((new CloudProviderTokenPolicy)->view($user, $token));
    }
}
