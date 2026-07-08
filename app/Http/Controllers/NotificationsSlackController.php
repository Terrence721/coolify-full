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

class NotificationsSlackController extends Controller
{
    use AuthorizesRequests;

    private const array TOGGLE_FIELDS = [
        'slack_enabled',
        'deployment_success_slack_notifications',
        'deployment_failure_slack_notifications',
        'status_change_slack_notifications',
        'backup_success_slack_notifications',
        'backup_failure_slack_notifications',
        'scheduled_task_success_slack_notifications',
        'scheduled_task_failure_slack_notifications',
        'docker_cleanup_success_slack_notifications',
        'docker_cleanup_failure_slack_notifications',
        'server_disk_usage_slack_notifications',
        'server_reachable_slack_notifications',
        'server_unreachable_slack_notifications',
        'server_patch_slack_notifications',
        'traefik_outdated_slack_notifications',
    ];

    public function edit(): Response
    {
        $settings = currentTeam()->slackNotificationSettings;
        $this->authorize('view', $settings);

        return Inertia::render('Notifications/Slack', [
            'settings' => $settings->only([...self::TOGGLE_FIELDS, 'slack_webhook_url']),
            'updateUrl' => route('notifications.slack.update'),
            'sendTestUrl' => route('notifications.slack.send-test'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $settings = currentTeam()->slackNotificationSettings;
        $this->authorize('update', $settings);

        $rules = ['slack_webhook_url' => ['nullable', new SafeWebhookUrl]];
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
        $settings = currentTeam()->slackNotificationSettings;
        $this->authorize('sendTest', $settings);

        currentTeam()->notify(new Test(channel: 'slack'));

        return back()->with('success', 'Test notification sent.');
    }
}
