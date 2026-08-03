<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

// Regression coverage for a real, critical account-takeover bug: OauthController::callback()
// matched an existing user purely by email and logged the browser in as them - the users table
// has no provider/provider_id link recorded anywhere, so login-by-email was the entire trust
// boundary. Any admin-configured OAuth provider that doesn't itself guarantee email ownership
// (a self-hosted GitLab/Authentik/Zitadel instance with confirmation disabled, or one the
// attacker controls) let an attacker log in as any existing victim account just by asserting
// the victim's email through the provider - no password needed. Fixed by recording which
// provider originally created each account (oauth_provider column) and only allowing an OAuth
// login to match an existing user when it's the same provider that created it; a password-only
// account (oauth_provider null) or one created via a different provider is rejected instead of
// silently logged in. Also covers a compounding bug in the same flow: get_socialite_provider()
// never checked OauthSetting::enabled - a provider an admin disabled still worked end-to-end via
// a direct request, since 'enabled' was only ever used to decide which login button to show.

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function fakeSocialiteFor(string $email, string $name = 'OAuth User'): void
{
    $fakeSocialiteUser = new class($email, $name)
    {
        public function __construct(public string $email, public string $name) {}
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
}

it('creates a new account with the originating provider recorded, and logs the user in', function () {
    OauthSetting::factory()->create(['provider' => 'github', 'enabled' => true]);
    fakeSocialiteFor('new-user@example.com');

    $response = $this->get(route('auth.callback', 'github'));

    $user = User::whereEmail('new-user@example.com')->firstOrFail();
    expect($user->oauth_provider)->toBe('github');
    $response->assertRedirect('/');
    expect(Auth::id())->toBe($user->id);
});

it('logs a returning user back in when the same provider that created their account is used again', function () {
    OauthSetting::factory()->create(['provider' => 'github', 'enabled' => true]);
    $user = User::factory()->create(['email' => 'returning@example.com', 'oauth_provider' => 'github']);
    fakeSocialiteFor('returning@example.com');

    $response = $this->get(route('auth.callback', 'github'));

    $response->assertRedirect('/');
    expect(Auth::id())->toBe($user->id);
});

it('refuses to log in as an existing password-only account instead of silently authenticating as it - the core account-takeover regression', function () {
    OauthSetting::factory()->create(['provider' => 'github', 'enabled' => true]);
    $victim = User::factory()->create(['email' => 'victim@example.com', 'password' => bcrypt('secret'), 'oauth_provider' => null]);
    fakeSocialiteFor('victim@example.com');

    $response = $this->get(route('auth.callback', 'github'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors();
    expect(Auth::check())->toBeFalse();
    expect(Auth::id())->not->toBe($victim->id);
});

it('refuses to log in when the account was created via a different provider', function () {
    OauthSetting::factory()->create(['provider' => 'github', 'enabled' => true]);
    OauthSetting::factory()->create(['provider' => 'gitlab', 'enabled' => true]);
    $victim = User::factory()->create(['email' => 'cross-provider@example.com', 'oauth_provider' => 'gitlab']);
    fakeSocialiteFor('cross-provider@example.com');

    $response = $this->get(route('auth.callback', 'github'));

    $response->assertRedirect(route('login'));
    expect(Auth::check())->toBeFalse();
    expect(Auth::id())->not->toBe($victim->id);
});

it('does not complete authentication via callback when the provider is disabled', function () {
    OauthSetting::factory()->create(['provider' => 'github', 'enabled' => false]);
    fakeSocialiteFor('someone@example.com');

    // The controller's own try/catch converts the 404 thrown inside get_socialite_provider()
    // into the same redirect-with-error response used for every other callback failure - the
    // important assertion is that authentication never completes, not the literal status code.
    $response = $this->get(route('auth.callback', 'github'));

    $response->assertRedirect(route('login'));
    expect(Auth::check())->toBeFalse();
});

it('returns a 404 on the redirect endpoint too when the provider is disabled', function () {
    OauthSetting::factory()->create(['provider' => 'github', 'enabled' => false]);

    $response = $this->get(route('auth.redirect', 'github'));

    $response->assertNotFound();
});
