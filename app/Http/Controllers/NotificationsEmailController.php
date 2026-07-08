<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Notifications\Test;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class NotificationsEmailController extends Controller
{
    use AuthorizesRequests;

    private const array TOGGLE_FIELDS = [
        'deployment_success_email_notifications',
        'deployment_failure_email_notifications',
        'status_change_email_notifications',
        'backup_success_email_notifications',
        'backup_failure_email_notifications',
        'scheduled_task_success_email_notifications',
        'scheduled_task_failure_email_notifications',
        'docker_cleanup_success_email_notifications',
        'docker_cleanup_failure_email_notifications',
        'server_disk_usage_email_notifications',
        'server_reachable_email_notifications',
        'server_unreachable_email_notifications',
        'server_patch_email_notifications',
        'traefik_outdated_email_notifications',
    ];

    public function edit(Request $request): Response
    {
        $team = currentTeam();
        $settings = $team->emailNotificationSettings;
        $this->authorize('view', $settings);

        $user = $request->user();
        $canSendTest = $user->isAdminFromSession()
            && Gate::forUser($user)->allows('sendTest', $settings)
            && $team->isNotificationEnabled('email');

        return Inertia::render('Notifications/Email', [
            'settings' => $settings->only([
                ...self::TOGGLE_FIELDS,
                'use_instance_email_settings', 'smtp_from_name', 'smtp_from_address',
                'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'smtp_timeout',
                'resend_enabled', 'resend_api_key',
            ]),
            'isCloud' => isCloud(),
            'isInstanceAdmin' => isInstanceAdmin(),
            'canSendTest' => $canSendTest,
            'testEmailAddress' => $user->email,
            'updateUrl' => route('notifications.email.update'),
            'smtpUpdateUrl' => route('notifications.email.update-smtp'),
            'resendUpdateUrl' => route('notifications.email.update-resend'),
            'sendTestUrl' => route('notifications.email.send-test'),
            'copyFromInstanceUrl' => route('notifications.email.copy-from-instance'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $settings = currentTeam()->emailNotificationSettings;
        $this->authorize('update', $settings);

        $rules = [
            'use_instance_email_settings' => ['boolean'],
            'smtp_from_name' => ['nullable', 'string'],
            'smtp_from_address' => ['nullable', 'email'],
        ];
        foreach (self::TOGGLE_FIELDS as $field) {
            $rules[$field] = ['boolean'];
        }

        $validated = Validator::make($request->all(), $rules)->validate();

        $settings->update($validated);

        return back()->with('success', 'Email notifications settings updated.');
    }

    public function updateSmtp(Request $request): RedirectResponse
    {
        $settings = currentTeam()->emailNotificationSettings;
        $this->authorize('update', $settings);

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

        $settings->update($validated);

        return back()->with('success', 'SMTP settings updated.');
    }

    public function updateResend(Request $request): RedirectResponse
    {
        $settings = currentTeam()->emailNotificationSettings;
        $this->authorize('update', $settings);

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

        $settings->update($validated);

        return back()->with('success', 'Resend settings updated.');
    }

    public function sendTest(Request $request): RedirectResponse
    {
        $team = currentTeam();
        $settings = $team->emailNotificationSettings;
        $this->authorize('sendTest', $settings);

        $validated = Validator::make($request->all(), [
            'test_email_address' => ['required', 'email'],
        ], [
            'test_email_address.required' => 'Test email address is required.',
        ])->validate();

        $executed = RateLimiter::attempt(
            'test-email:'.$team->id,
            0,
            function () use ($team, $validated) {
                $team->notifyNow(new Test($validated['test_email_address'], 'email'));
            },
            10,
        );

        if (! $executed) {
            return back()->with('error', 'Too many messages sent!');
        }

        return back()->with('success', 'Test Email sent.');
    }

    public function copyFromInstance(): RedirectResponse
    {
        $settings = currentTeam()->emailNotificationSettings;
        $this->authorize('update', $settings);

        $instance = instanceSettings();

        $data = [
            'smtp_from_address' => $instance->smtp_from_address,
            'smtp_from_name' => $instance->smtp_from_name,
            'smtp_recipients' => $instance->smtp_recipients,
            'smtp_host' => $instance->smtp_host,
            'smtp_port' => $instance->smtp_port,
            'smtp_encryption' => $instance->smtp_encryption,
            'smtp_username' => $instance->smtp_username,
            'smtp_password' => $instance->smtp_password,
            'smtp_timeout' => $instance->smtp_timeout,
            'resend_api_key' => $instance->resend_api_key,
        ];

        if ($instance->smtp_enabled) {
            $data['smtp_enabled'] = true;
            $data['resend_enabled'] = false;
        }

        if ($instance->resend_enabled) {
            $data['resend_enabled'] = true;
            $data['smtp_enabled'] = false;
        }

        $settings->update($data);

        return back()->with('success', 'Settings copied from instance settings.');
    }
}
