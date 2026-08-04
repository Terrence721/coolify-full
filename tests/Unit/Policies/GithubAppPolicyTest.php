<?php

declare(strict_types=1);

use App\Models\GithubApp;
use App\Models\Team;
use App\Models\User;
use App\Policies\GithubAppPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// GithubAppPolicy::update()/delete() checked $user->isAdmin() (the role in the user's *current
// session team*, per User::role() -> currentTeam() -> session('currentTeam')) ANDed with mere
// team membership in the target app's owning team - not admin *of that owning team*. A user who
// is admin of their own current team but only a plain member of a different team could pass this
// combined check for any GithubApp owned by that other team, including modifying/deleting a
// system-wide app's client_secret/webhook_secret. Confirmed via SourceGithubController::update()/
// destroy() (tests/v4/Feature/SourceGithubChangeTest.php) that this was genuinely exploitable for
// system-wide apps, real reachable, real secrets. The identical broken pattern also existed in the
// non-system-wide branch, but GithubApp::ownedByCurrentTeam()'s route-model-resolution scope
// (only matches team_id === currentTeam OR is_system_wide) already 404s before authorize() is
// ever reached for that case through this specific controller - fixed anyway for policy-level
// correctness/defense-in-depth, since nothing guarantees every future caller uses that same scope.
// The correct pattern (isAdminOfTeam($teamId), not isAdmin()) already existed elsewhere in this
// codebase, in PrivateKeyPolicy::update()/delete().

function makeTeamAdminOfDifferentTeam(Team $ownAdminTeam, Team $otherTeam): User
{
    $user = User::factory()->create();
    $ownAdminTeam->members()->attach($user, ['role' => 'admin']);
    $otherTeam->members()->attach($user, ['role' => 'member']);
    session(['currentTeam' => $ownAdminTeam]);

    return $user;
}

it('denies update on a system-wide app owned by a different team, even for an admin of the current session team', function () {
    $sessionTeam = Team::factory()->create();
    $owningTeam = Team::factory()->create();
    $user = makeTeamAdminOfDifferentTeam($sessionTeam, $owningTeam);
    $githubApp = GithubApp::create(['team_id' => $owningTeam->id, 'is_system_wide' => true, 'name' => 'app', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com']);

    expect((new GithubAppPolicy)->update($user, $githubApp))->toBeFalse();
});

it('denies delete on a system-wide app owned by a different team, even for an admin of the current session team', function () {
    $sessionTeam = Team::factory()->create();
    $owningTeam = Team::factory()->create();
    $user = makeTeamAdminOfDifferentTeam($sessionTeam, $owningTeam);
    $githubApp = GithubApp::create(['team_id' => $owningTeam->id, 'is_system_wide' => true, 'name' => 'app', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com']);

    expect((new GithubAppPolicy)->delete($user, $githubApp))->toBeFalse();
});

it('denies update on a non-system-wide app owned by a different team, even for an admin of the current session team', function () {
    $sessionTeam = Team::factory()->create();
    $owningTeam = Team::factory()->create();
    $user = makeTeamAdminOfDifferentTeam($sessionTeam, $owningTeam);
    $githubApp = GithubApp::create(['team_id' => $owningTeam->id, 'is_system_wide' => false, 'name' => 'app', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com']);

    expect((new GithubAppPolicy)->update($user, $githubApp))->toBeFalse();
});

it('denies delete on a non-system-wide app owned by a different team, even for an admin of the current session team', function () {
    $sessionTeam = Team::factory()->create();
    $owningTeam = Team::factory()->create();
    $user = makeTeamAdminOfDifferentTeam($sessionTeam, $owningTeam);
    $githubApp = GithubApp::create(['team_id' => $owningTeam->id, 'is_system_wide' => false, 'name' => 'app', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com']);

    expect((new GithubAppPolicy)->delete($user, $githubApp))->toBeFalse();
});

it('still allows update/delete for the owning team\'s own admin', function () {
    $owningTeam = Team::factory()->create();
    $user = User::factory()->create();
    $owningTeam->members()->attach($user, ['role' => 'admin']);
    session(['currentTeam' => $owningTeam]);
    $systemWideApp = GithubApp::create(['team_id' => $owningTeam->id, 'is_system_wide' => true, 'name' => 'a', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com']);
    $scopedApp = GithubApp::create(['team_id' => $owningTeam->id, 'is_system_wide' => false, 'name' => 'b', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com']);

    $policy = new GithubAppPolicy;

    expect($policy->update($user, $systemWideApp))->toBeTrue();
    expect($policy->delete($user, $systemWideApp))->toBeTrue();
    expect($policy->update($user, $scopedApp))->toBeTrue();
    expect($policy->delete($user, $scopedApp))->toBeTrue();
});
