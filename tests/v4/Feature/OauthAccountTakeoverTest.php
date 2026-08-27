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
    fakeSocialiteForWithSideEffect($email, fn () => null, $name);
}

/**
 * Same as fakeSocialiteFor(), but runs $sideEffect() while "fetching" the OAuth user - the
 * synchronous stand-in for whatever could happen during the real network round-trip to the
 * IdP that sits between get_socialite_provider()'s own enabled check and the code that runs
 * after user() returns.
 */
function fakeSocialiteForWithSideEffect(string $email, Closure $sideEffect, string $name = 'OAuth User'): void
{
    $fakeSocialiteUser = new class($email, $name)
    {
        public function __construct(public string $email, public string $name) {}
    };
    $fakeProvider = new class($fakeSocialiteUser, $sideEffect)
    {
        public function __construct(private object $user, private Closure $sideEffect) {}

        public function user(): object
        {
            ($this->sideEffect)();

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

it('migrates existing OAuth-only accounts (oauth_provider = null, password = null) and backfills the provider on first login', function () {
    // Regression coverage for the migration case: accounts created via OAuth before provider
    // tracking was added have oauth_provider = null and password = null. The login flow should
    // recognize them as existing OAuth accounts (not password-only accounts) by checking both
    // conditions, allow the login, and backfill oauth_provider to the provider they're using now.
    OauthSetting::factory()->create(['provider' => 'github', 'enabled' => true]);
    $existingOauthUser = User::factory()->create([
        'email' => 'existing-oauth@example.com',
        'oauth_provider' => null,
        'password' => null,
    ]);
    fakeSocialiteFor('existing-oauth@example.com');

    $response = $this->get(route('auth.callback', 'github'));

    $response->assertRedirect('/');
    expect(Auth::id())->toBe($existingOauthUser->id);
    expect($existingOauthUser->fresh()->oauth_provider)->toBe('github');
});

it('refuses to backfill a legacy OAuth-only account when more than one provider is enabled - reopened account-takeover window', function () {
    // Regression coverage for a real gap the migration-backfill fix above left behind: since a
    // legacy account (oauth_provider = null, password = null) has no record of which provider
    // actually created it, unconditionally trusting the first provider to assert its email lets
    // *any* currently enabled provider claim it - exactly the takeover the surrounding check
    // exists to prevent, just scoped to this account population instead of every account. Only
    // safe to backfill when exactly one provider is enabled, since then there's no other provider
    // that could be asserting a false claim.
    OauthSetting::factory()->create(['provider' => 'github', 'enabled' => true]);
    OauthSetting::factory()->create(['provider' => 'gitlab', 'enabled' => true]);
    $victim = User::factory()->create([
        'email' => 'legacy-oauth-victim@example.com',
        'oauth_provider' => null,
        'password' => null,
    ]);
    fakeSocialiteFor('legacy-oauth-victim@example.com');

    $response = $this->get(route('auth.callback', 'gitlab'));

    $response->assertRedirect(route('login'));
    expect(Auth::check())->toBeFalse();
    expect($victim->fresh()->oauth_provider)->toBeNull();
});

it('does not backfill a legacy account to a provider that was disabled during the OAuth round trip', function () {
    // TOCTOU regression: get_socialite_provider() confirms $provider is enabled before the
    // network round-trip to the IdP; the backfill decision runs after that round-trip. If an
    // admin disables $provider and enables a different one in that window, a bare
    // enabled-count of 1 would pass against the *new* sole-enabled provider while backfilling
    // $provider, which is no longer enabled at all - reopening the takeover this check exists
    // to prevent.
    $github = OauthSetting::factory()->create(['provider' => 'github', 'enabled' => true]);
    OauthSetting::factory()->create(['provider' => 'gitlab', 'enabled' => false]);
    $victim = User::factory()->create([
        'email' => 'legacy-toctou-victim@example.com',
        'oauth_provider' => null,
        'password' => null,
    ]);

    fakeSocialiteForWithSideEffect('legacy-toctou-victim@example.com', function () use ($github) {
        $github->update(['enabled' => false]);
        OauthSetting::where('provider', 'gitlab')->update(['enabled' => true]);
    });

    $response = $this->get(route('auth.callback', 'github'));

    $response->assertRedirect(route('login'));
    expect(Auth::check())->toBeFalse();
    expect($victim->fresh()->oauth_provider)->toBeNull();
});
