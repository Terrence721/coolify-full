<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Notifications\Test;
use App\Rules\SafeWebhookUrl;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class NotificationsWebhookController extends Controller
{
    use AuthorizesRequests;

    private const array TOGGLE_FIELDS = [
        'webhook_enabled',
        'deployment_success_webhook_notifications',
        'deployment_failure_webhook_notifications',
        'status_change_webhook_notifications',
        'backup_success_webhook_notifications',
        'backup_failure_webhook_notifications',
        'scheduled_task_success_webhook_notifications',
        'scheduled_task_failure_webhook_notifications',
        'docker_cleanup_success_webhook_notifications',
        'docker_cleanup_failure_webhook_notifications',
        'server_disk_usage_webhook_notifications',
        'server_reachable_webhook_notifications',
        'server_unreachable_webhook_notifications',
        'server_patch_webhook_notifications',
        'traefik_outdated_webhook_notifications',
    ];

    public function edit(): Response
    {
        $settings = currentTeam()->webhookNotificationSettings;
        $this->authorize('view', $settings);

        return Inertia::render('Notifications/Webhook', [
            'settings' => $settings->only([...self::TOGGLE_FIELDS, 'webhook_url']),
            'updateUrl' => route('notifications.webhook.update'),
            'sendTestUrl' => route('notifications.webhook.send-test'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $settings = currentTeam()->webhookNotificationSettings;
        $this->authorize('update', $settings);

        $rules = ['webhook_url' => ['nullable', new SafeWebhookUrl]];
        foreach (self::TOGGLE_FIELDS as $field) {
            $rules[$field] = ['boolean'];
        }

        $validated = Validator::make($request->all(), $rules)->validate();

        $settings->update($validated);
        refreshSession();

        return back()->with('success', 'Settings saved.');
    }

    public function sendTest(): RedirectResponse
    {
        $settings = currentTeam()->webhookNotificationSettings;
        $this->authorize('sendTest', $settings);

        currentTeam()->notify(new Test(channel: 'webhook'));

        return back()->with('success', 'Test notification sent.');
    }
}
