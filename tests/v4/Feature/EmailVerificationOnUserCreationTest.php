<?php

declare(strict_types=1);

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\OauthController;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

// Regression coverage for a real bug found during the /admin impersonation smoke test: three
// separate user-creation paths (the first/root user via registration, team-invited users, and
// OAuth signups) never set email_verified_at. In non-cloud mode, DecideWhatToDoWithUser's own
// unverified-email redirect is skipped entirely (it only runs when isCloud()), so these users
// fell straight through to Laravel's stock 'verified' middleware - which crashed with a 500
// (RouteNotFoundException: Route [verification.notice] not defined) instead of showing a
// verify-email screen, since this app's verification routes are named verify.* rather than
// Laravel's convention. Fixed by (1) registering verification.notice as an alias for the existing
// /verify route, and (2) auto-verifying new users in non-cloud mode (matching the pattern
// CreateNewUser's non-root branch already used), sending a real verification email only on cloud.
// OAuth users are always auto-verified regardless of cloud/non-cloud, since the provider already
// confirmed the email as part of its own login flow.

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function enableFakeSmtp(): void
{
    InstanceSettings::get()->update([
        'smtp_enabled' => true,
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 587,
        'smtp_encryption' => 'tls',
        'smtp_from_address' => 'coolify@example.com',
        'smtp_from_name' => 'Coolify',
    ]);
}

it('registers a verification.notice route, matching what Laravel\'s stock verified middleware expects', function () {
    expect(route('verification.notice'))->toBe(url('/verify'));
});

it('auto-verifies the first (root) user on a non-cloud instance, and they can immediately reach a verified-protected route', function () {
    config(['constants.coolify.self_hosted' => true]);
    expect(User::count())->toBe(0);

    $user = (new CreateNewUser)->create([
        'name' => 'Root User',
        'email' => 'root@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect($user->id)->toBe(0);
    expect($user->hasVerifiedEmail())->toBeTrue();

    // A brand new team still has show_boarding=true, so DecideWhatToDoWithUser legitimately
    // redirects to onboarding - the actual regression this guards against is a 500 (crashing on
    // the 'verified' middleware before ever reaching that check), not this expected redirect.
    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $user->teams->first()])
        ->get('/');

    $response->assertRedirect(route('onboarding'));
});

it('sends a verification email instead of auto-verifying the first user on a cloud instance', function () {
    config(['constants.coolify.self_hosted' => false]);
    Mail::fake();
    enableFakeSmtp();
    expect(User::count())->toBe(0);

    $user = (new CreateNewUser)->create([
        'name' => 'Root User',
        'email' => 'root@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect($user->hasVerifiedEmail())->toBeFalse();
});

it('auto-verifies a team-invited user on a non-cloud instance', function () {
    config(['constants.coolify.self_hosted' => true]);
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => 'owner']);

    $response = $this->actingAs($owner)
        ->withSession(['currentTeam' => $team])
        ->post(route('team.invitation.send'), [
            'email' => 'invited-member@example.com',
            'role' => 'member',
            'via' => 'link',
        ]);

    $response->assertSessionDoesntHaveErrors();
    $invited = User::whereEmail('invited-member@example.com')->firstOrFail();
    expect($invited->hasVerifiedEmail())->toBeTrue();
});

it('sends a verification email instead of auto-verifying a team-invited user on a cloud instance', function () {
    config(['constants.coolify.self_hosted' => false]);
    Mail::fake();
    enableFakeSmtp();
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => 'owner']);

    $response = $this->actingAs($owner)
        ->withSession(['currentTeam' => $team])
        ->post(route('team.invitation.send'), [
            'email' => 'invited-member@example.com',
            'role' => 'member',
            'via' => 'link',
        ]);

    $response->assertSessionDoesntHaveErrors();
    $invited = User::whereEmail('invited-member@example.com')->firstOrFail();
    expect($invited->hasVerifiedEmail())->toBeFalse();
});

it('always auto-verifies a new OAuth-signup user, regardless of cloud/non-cloud', function () {
    config(['constants.coolify.self_hosted' => true]);
    OauthSetting::factory()->create([
        'provider' => 'github',
        'client_id' => 'fake-id',
        'client_secret' => 'fake-secret',
        'enabled' => true,
    ]);

    $fakeSocialiteUser = new class
    {
        public string $email = 'oauth-signup@example.com';

        public string $name = 'OAuth Signup';
    };
    $fakeProvider = new class($fakeSocialiteUser)
    {
        public function __construct(private object $user) {}

        public function user(): object
        {
            return $this->user;
        }
    };
    Socialite::shouldReceive('buildProvider')->andReturn($fakeProvider);

    (new OauthController)->callback('github');

    $user = User::whereEmail('oauth-signup@example.com')->firstOrFail();
    expect($user->hasVerifiedEmail())->toBeTrue();
});
