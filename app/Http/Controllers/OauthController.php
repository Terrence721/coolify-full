<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OauthController extends Controller
{
    public function redirect(string $provider): mixed
    {
        $socialite_provider = get_socialite_provider($provider);

        return $socialite_provider->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        try {
            $oauthUser = get_socialite_provider($provider)->user();
            $email = trim((string) $oauthUser->email);
            if ($email === '') {
                abort(403, 'OAuth provider did not return an email address');
            }
            $email = strtolower($email);
            $user = User::whereEmail($email)->first();
            if (! $user) {
                $settings = instanceSettings();
                if (! $settings->is_registration_enabled) {
                    abort(403, 'Registration is disabled');
                }

                $user = User::create([
                    'name' => $oauthUser->name,
                    'email' => $email,
                    'oauth_provider' => $provider,
                ]);
                // The OAuth provider already confirmed this email address as part of its own
                // login flow, regardless of cloud/non-cloud - unlike the other user-creation
                // paths, there's no "send our own verification email" case here at all.
                $user->markEmailAsVerified();
            } elseif ($user->oauth_provider !== $provider) {
                // An existing account was found by email alone. Without this check, anyone who
                // can get an OAuth provider to assert a given email address - trivial on a
                // self-hosted/admin-configured provider with no email verification of its own -
                // would be logged in AS that account, no password needed. Only allow the login
                // if this account was originally created via this exact provider.
                //
                // Migration edge case: before oauth_provider tracking was added, OAuth-only
                // accounts had oauth_provider = NULL. Detect these by checking if both
                // oauth_provider and password are null - no other code path leaves password
                // null, so this combination can only be a pre-migration OAuth account.
                //
                // But we don't know which provider *originally* created it, so backfilling
                // unconditionally on the first successful email match would let any currently
                // enabled provider claim the account - reopening the exact takeover this check
                // exists to close, just scoped to legacy accounts instead of every account. Only
                // safe when exactly one provider is enabled AND it's this one: in that case "the
                // provider asserting this login" is unambiguously the only provider that could
                // have created it. With 2+ enabled providers, fail closed rather than guess - the
                // account stays locked until an admin narrows it back down to one provider (or
                // resets the password).
                //
                // The enabled check has to name $provider explicitly, not just count()===1: the
                // enabled-provider check at the top of this method (inside get_socialite_provider())
                // runs before the network round-trip to the OAuth IdP above. If an admin disables
                // $provider and enables a different one during that round-trip, a bare count()===1
                // here would pass against the *new* sole-enabled provider while backfilling
                // $provider, which is no longer enabled at all - re-opening the takeover this
                // whole check exists to prevent.
                $isLegacyOauthAccount = $user->oauth_provider === null && ! $user->hasPassword();
                $enabledProviders = OauthSetting::where('enabled', true)->pluck('provider');
                if ($isLegacyOauthAccount && $enabledProviders->count() === 1 && $enabledProviders->first() === $provider) {
                    $user->update(['oauth_provider' => $provider]);
                } else {
                    // Either a password-only account, one created via a different provider, or a
                    // legacy OAuth account that can't be safely attributed to this provider alone
                    abort(403, 'An account with this email already exists. Please log in with your password.');
                }
            }
            Auth::login($user);

            return redirect('/');
        } catch (\Exception $e) {
            $errorCode = $e instanceof HttpException ? 'auth.failed' : 'auth.failed.callback';

            return redirect()->route('login')->withErrors([__($errorCode)]);
        }
    }
}
