<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
                // if this account was originally created via this exact provider; a password-only
                // account (oauth_provider is null) or one created via a different provider both
                // fail this check and are rejected rather than silently logged in.
                abort(403, 'An account with this email already exists. Please log in with your password.');
            }
            Auth::login($user);

            return redirect('/');
        } catch (\Exception $e) {
            $errorCode = $e instanceof HttpException ? 'auth.failed' : 'auth.failed.callback';

            return redirect()->route('login')->withErrors([__($errorCode)]);
        }
    }
}
