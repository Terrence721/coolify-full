<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Notifications\Test;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class NotificationsPushoverController extends Controller
{
    use AuthorizesRequests;

    private const array TOGGLE_FIELDS = [
        'pushover_enabled',
        'deployment_success_pushover_notifications',
        'deployment_failure_pushover_notifications',
        'status_change_pushover_notifications',
        'backup_success_pushover_notifications',
        'backup_failure_pushover_notifications',
        'scheduled_task_success_pushover_notifications',
        'scheduled_task_failure_pushover_notifications',
        'docker_cleanup_success_pushover_notifications',
        'docker_cleanup_failure_pushover_notifications',
        'server_disk_usage_pushover_notifications',
        'server_reachable_pushover_notifications',
        'server_unreachable_pushover_notifications',
        'server_patch_pushover_notifications',
        'traefik_outdated_pushover_notifications',
    ];

    public function edit(): Response
    {
        $settings = currentTeam()->pushoverNotificationSettings;
        $this->authorize('view', $settings);

        return Inertia::render('Notifications/Pushover', [
            'settings' => $settings->only([...self::TOGGLE_FIELDS, 'pushover_user_key', 'pushover_api_token']),
            'updateUrl' => route('notifications.pushover.update'),
            'sendTestUrl' => route('notifications.pushover.send-test'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $settings = currentTeam()->pushoverNotificationSettings;
        $this->authorize('update', $settings);

        $rules = [
            'pushover_user_key' => ['nullable', 'string'],
            'pushover_api_token' => ['nullable', 'string'],
        ];
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
        $settings = currentTeam()->pushoverNotificationSettings;
        $this->authorize('sendTest', $settings);

        currentTeam()->notify(new Test(channel: 'pushover'));

        return back()->with('success', 'Test notification sent.');
    }
}
