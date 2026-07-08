<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends BaseController
{
    public function appearance(): Response
    {
        return Inertia::render('Profile/Appearance');
    }

    public function index(): Response
    {
        $user = Auth::user();

        return Inertia::render('Profile/Index', [
            'name' => $user->name,
            'email' => $user->email,
            'pendingEmail' => $user->hasEmailChangeRequest() ? $user->pending_email : null,
            'showVerification' => $user->hasEmailChangeRequest(),
            'verificationExpiryMinutes' => config('constants.email_change.verification_code_expiry_minutes', 10),
            'updateUrl' => route('profile.update'),
            'requestEmailChangeUrl' => route('profile.email.request'),
            'verifyEmailChangeUrl' => route('profile.email.verify'),
            'resendCodeUrl' => route('profile.email.resend'),
            'cancelEmailChangeUrl' => route('profile.email.cancel'),
            'updatePasswordUrl' => route('profile.password.update'),
            'twoFactor' => [
                'confirmed' => (bool) $user->two_factor_confirmed_at,
                'status' => session('status'),
                'qrCodeSvg' => session('status') === 'two-factor-authentication-enabled' ? $user->twoFactorQrCodeSvg() : null,
                'qrCodeUrl' => session('status') === 'two-factor-authentication-enabled' ? $user->twoFactorQrCodeUrl() : null,
                'secret' => session('status') === 'two-factor-authentication-enabled' ? decrypt($user->two_factor_secret) : null,
                'recoveryCodes' => in_array(session('status'), ['two-factor-authentication-confirmed', 'recovery-codes-generated'])
                    ? $user->recoveryCodes()
                    : null,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = Validator::make($request->all(), [
            'name' => ['required', 'string'],
        ])->validate();

        Auth::user()->update($validated);

        return back()->with('success', 'Profile updated.');
    }

    public function requestEmailChange(Request $request): RedirectResponse
    {
        if (! isCloud()) {
            $settings = instanceSettings();
            if (! $settings->smtp_enabled && ! $settings->resend_enabled) {
                return back()->with('error', 'Email functionality is not configured. Please contact your administrator.');
            }
        }

        $validated = Validator::make($request->all(), [
            'new_email' => ['required', 'email', 'unique:users,email'],
        ])->validate();

        $newEmail = strtolower($validated['new_email']);

        if (! isDev()) {
            $userEmailKey = 'email-change:user:'.Auth::id();
            if (! RateLimiter::attempt($userEmailKey, 1, function () {}, 120)) {
                $seconds = RateLimiter::availableIn($userEmailKey);

                return back()->with('error', 'Too many requests. Please wait '.$seconds.' seconds before trying again.');
            }

            $newEmailKey = 'email-change:email:'.md5($newEmail);
            if (! RateLimiter::attempt($newEmailKey, 3, function () {}, 3600)) {
                return back()->with('error', 'This email address has received too many verification requests. Please try again later.');
            }

            $ipKey = 'email-change:ip:'.$request->ip();
            if (! RateLimiter::attempt($ipKey, 5, function () {}, 3600)) {
                return back()->with('error', 'Too many requests from your IP address. Please try again later.');
            }
        }

        Auth::user()->requestEmailChange($newEmail);

        return back()->with('success', 'Verification code sent to '.$newEmail);
    }

    public function verifyEmailChange(Request $request): RedirectResponse
    {
        $validated = Validator::make($request->all(), [
            'email_verification_code' => ['required', 'string', 'size:6'],
        ])->validate();

        $user = Auth::user();

        if (! isDev()) {
            $verifyKey = 'email-verify:user:'.Auth::id();
            if (! RateLimiter::attempt($verifyKey, 5, function () {}, 600)) {
                $seconds = RateLimiter::availableIn($verifyKey);
                $minutes = ceil($seconds / 60);

                if (RateLimiter::attempts($verifyKey) >= 10) {
                    $user->clearEmailChangeRequest();

                    return back()->with('error', 'Email change request cancelled due to too many failed attempts. Please start over.');
                }

                return back()->with('error', 'Too many verification attempts. Please wait '.$minutes.' minutes before trying again.');
            }
        }

        if (! $user->isEmailChangeCodeValid($validated['email_verification_code'])) {
            return back()->with('error', 'Invalid or expired verification code.');
        }

        if ($user->confirmEmailChange($validated['email_verification_code'])) {
            if (! isDev()) {
                RateLimiter::clear('email-verify:user:'.Auth::id());
            }

            return back()->with('success', 'Email address updated successfully.');
        }

        return back()->with('error', 'Failed to update email address.');
    }

    public function resendVerificationCode(): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasEmailChangeRequest()) {
            return back()->with('error', 'No pending email change request.');
        }

        $expiryMinutes = config('constants.email_change.verification_code_expiry_minutes', 10);
        $halfExpiryMinutes = $expiryMinutes / 2;
        $codeExpiry = $user->email_change_code_expires_at;
        $timeSinceCreated = $codeExpiry->subMinutes($expiryMinutes)->diffInMinutes(now());

        if ($timeSinceCreated < $halfExpiryMinutes) {
            $minutesToWait = ceil($halfExpiryMinutes - $timeSinceCreated);

            return back()->with('error', 'Please wait '.$minutesToWait.' more minutes before requesting a new code.');
        }

        $pendingEmail = $user->pending_email;

        if (! isDev()) {
            $newEmailKey = 'email-change:email:'.md5(strtolower($pendingEmail));
            if (! RateLimiter::attempt($newEmailKey, 3, function () {}, 3600)) {
                return back()->with('error', 'This email address has received too many verification requests. Please try again later.');
            }
        }

        $user->requestEmailChange($pendingEmail);

        return back()->with('success', 'New verification code sent to '.$pendingEmail);
    }

    public function cancelEmailChange(): RedirectResponse
    {
        Auth::user()->clearEmailChangeRequest();

        return back()->with('success', 'Email change request cancelled.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = Validator::make($request->all(), [
            'current_password' => ['required'],
            'new_password' => ['required', Password::defaults(), 'confirmed'],
        ])->validate();

        if (! Hash::check($validated['current_password'], Auth::user()->password)) {
            return back()->with('error', 'Current password is incorrect.');
        }

        Auth::user()->update([
            'password' => Hash::make($validated['new_password']),
        ]);

        return back()->with('success', 'Password updated.');
    }
}
