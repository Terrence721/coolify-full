<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Notifications\TransactionalEmails\Test;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class SettingsEmailController extends Controller
{
    public function edit(Request $request): Response|RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $settings = instanceSettings();

        return Inertia::render('SettingsEmail', [
            'settings' => $settings->only([
                'smtp_enabled', 'smtp_from_address', 'smtp_from_name',
                'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'smtp_timeout',
                'resend_enabled', 'resend_api_key',
            ]),
            'canSendTest' => is_transactional_emails_enabled() && $request->user()->isAdminFromSession(),
            'testEmailAddress' => $request->user()->email,
            'smtpUpdateUrl' => route('settings.email.update-smtp'),
            'resendUpdateUrl' => route('settings.email.update-resend'),
            'sendTestUrl' => route('settings.email.send-test'),
        ]);
    }

    public function updateSmtp(Request $request): RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $validated = Validator::make($request->all(), [
            'smtp_enabled' => ['boolean'],
            'smtp_from_address' => ['required', 'email'],
            'smtp_from_name' => ['required', 'string'],
            'smtp_host' => ['required', 'string'],
            'smtp_port' => ['required', 'numeric'],
            'smtp_encryption' => ['required', 'string', 'in:starttls,tls,none'],
            'smtp_username' => ['nullable', 'string'],
            'smtp_password' => ['nullable', 'string'],
            'smtp_timeout' => ['nullable', 'numeric'],
        ], [
            'smtp_from_address.required' => 'From Address is required.',
            'smtp_from_name.required' => 'From Name is required.',
            'smtp_host.required' => 'SMTP Host is required.',
            'smtp_port.required' => 'SMTP Port is required.',
            'smtp_port.numeric' => 'SMTP Port must be a number.',
            'smtp_encryption.required' => 'Encryption type is required.',
        ])->validate();

        if ($validated['smtp_enabled']) {
            $validated['resend_enabled'] = false;
        }

        instanceSettings()->update($validated);

        return back()->with('success', 'SMTP settings updated.');
    }

    public function updateResend(Request $request): RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $validated = Validator::make($request->all(), [
            'resend_enabled' => ['boolean'],
            'resend_api_key' => ['required', 'string'],
            'smtp_from_address' => ['required', 'email'],
            'smtp_from_name' => ['required', 'string'],
        ], [
            'resend_api_key.required' => 'Resend API Key is required.',
            'smtp_from_address.required' => 'From Address is required.',
            'smtp_from_name.required' => 'From Name is required.',
        ])->validate();

        if ($validated['resend_enabled']) {
            $validated['smtp_enabled'] = false;
        }

        instanceSettings()->update($validated);

        return back()->with('success', 'Resend settings updated.');
    }

    public function sendTest(Request $request): RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $validated = Validator::make($request->all(), [
            'test_email_address' => ['required', 'email'],
        ], [
            'test_email_address.required' => 'Test email address is required.',
        ])->validate();

        $executed = RateLimiter::attempt(
            'test-email:'.currentTeam()->id,
            0,
            function () use ($validated) {
                currentTeam()->notifyNow(new Test($validated['test_email_address']));
            },
            10,
        );

        if (! $executed) {
            return back()->with('error', 'Too many messages sent!');
        }

        return back()->with('success', 'Test Email sent.');
    }
}
