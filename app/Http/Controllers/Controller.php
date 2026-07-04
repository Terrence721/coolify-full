<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\TestEvent;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    public function realtime_test(): Redirector|RedirectResponse|string
    {
        if (data_get(currentTeam(), 'id') !== 0) {
            return redirect(RouteServiceProvider::HOME);
        }
        TestEvent::dispatch();

        return 'Look at your other tab.';
    }

    public function verify(): Factory|View
    {
        return view('auth.verify-email');
    }

    public function email_verify(Request $request): Redirector|RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            abort(403);
        }

        $user = Auth::user();
        if (! $user instanceof User) {
            abort(403);
        }

        if (! hash_equals((string) $request->route('id'), (string) $user->getAuthIdentifier())) {
            abort(403);
        }

        if (! hash_equals((string) $request->route('hash'), hash('sha256', $user->getEmailForVerification()))) {
            abort(403);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return redirect(RouteServiceProvider::HOME);
    }

    public function forgot_password(Request $request): mixed
    {
        if (is_transactional_emails_enabled()) {
            $arrayOfRequest = $request->only(Fortify::email());
            $request->merge([
                'email' => Str::lower($arrayOfRequest['email']),
            ]);
            $type = set_transanctional_email_settings();
            if (blank($type)) {
                return response()->json(['message' => 'Transactional emails are not active'], 400);
            }
            $request->validate([Fortify::email() => 'required|email']);
            $status = Password::broker(config('fortify.passwords'))->sendResetLink(
                $request->only(Fortify::email())
            );
            if ($status == Password::RESET_LINK_SENT) {
                return app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => $status]);
            }
            if ($status == Password::RESET_THROTTLED) {
                return response('Already requested a password reset in the past minutes.', 400);
            }

            return app(FailedPasswordResetLinkRequestResponse::class, ['status' => $status]);
        }

        return response()->json(['message' => 'Transactional emails are not active'], 400);
    }

    public function link(): RedirectResponse
    {
        $token = request()->get('token');
        if ($token) {
            try {
                $decrypted = Crypt::decryptString($token);
            } catch (DecryptException) {
                return redirect()->route('login')->with('error', 'Invalid credentials.');
            }

            if (! str_contains($decrypted, '@@@')) {
                return redirect()->route('login')->with('error', 'Invalid credentials.');
            }

            $payload = explode('@@@', $decrypted, 3);
            if (count($payload) === 3) {
                [$email, $invitationUuid, $password] = $payload;
            } else {
                [$email, $password] = $payload;
                $invitationUuid = null;
            }

            $email = Str::lower($email);
            $user = User::whereEmail($email)->first();
            if (! $user) {
                return redirect()->route('login');
            }

            $invitation = TeamInvitation::query()
                ->where('email', $email)
                ->when($invitationUuid, fn ($query) => $query->where('uuid', $invitationUuid))
                ->where('link', request()->fullUrl())
                ->first();
            if (! $invitation || ! $invitation->isValid()) {
                return redirect()->route('login')->with('error', 'Invitation has expired or been revoked.');
            }

            if (Hash::check($password, $user->password)) {
                $team = $invitation->team()->first();
                if (! $team) {
                    return redirect()->route('login')->with('error', 'Invitation team not found.');
                }
                if (! $user->teams()->where('team_id', $team->id)->exists()) {
                    $user->teams()->attach($team->id, ['role' => $invitation->role]);
                }
                $invitation->delete();

                Auth::login($user);
                $user->forceFill([
                    'password' => Hash::make(Str::random(64)),
                ])->save();
                session(['currentTeam' => $team]);

                return redirect()->route('dashboard');
            }
        }

        return redirect()->route('login')->with('error', 'Invalid credentials.');
    }

    public function showInvitation(): Factory|View
    {
        $invitationUuid = request()->route('uuid');
        $invitation = TeamInvitation::whereUuid($invitationUuid)->firstOrFail();
        $user = User::whereEmail($invitation->email)->firstOrFail();

        if (Auth::id() !== $user->id) {
            abort(400, 'You are not allowed to accept this invitation.');
        }

        if (! $invitation->isValid()) {
            abort(400, 'Invitation expired.');
        }

        $team = $invitation->team()->first();
        if (! $team) {
            abort(404, 'Team not found.');
        }

        $alreadyMember = $user->teams()->where('team_id', $team->id)->exists();

        return view('invitation.accept', [
            'invitation' => $invitation,
            'team' => $team,
            'alreadyMember' => $alreadyMember,
        ]);
    }

    public function acceptInvitation(): RedirectResponse
    {
        $invitationUuid = request()->route('uuid');

        $invitation = TeamInvitation::whereUuid($invitationUuid)->firstOrFail();
        $user = User::whereEmail($invitation->email)->firstOrFail();

        if (Auth::id() !== $user->id) {
            abort(400, 'You are not allowed to accept this invitation.');
        }

        if (! $invitation->isValid()) {
            abort(400, 'Invitation expired.');
        }

        $team = $invitation->team()->first();
        if (! $team) {
            abort(404, 'Team not found.');
        }

        if ($user->teams()->where('team_id', $team->id)->exists()) {
            $invitation->delete();

            return redirect()->route('team.index');
        }
        $user->teams()->attach($team->id, ['role' => $invitation->role]);
        $invitation->delete();

        refreshSession($team);

        return redirect()->route('team.index');
    }
}
