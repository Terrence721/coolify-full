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

class NotificationsTelegramController extends Controller
{
    use AuthorizesRequests;

    private const array TOGGLE_FIELDS = [
        'deployment_success_telegram_notifications',
        'deployment_failure_telegram_notifications',
        'status_change_telegram_notifications',
        'backup_success_telegram_notifications',
        'backup_failure_telegram_notifications',
        'scheduled_task_success_telegram_notifications',
        'scheduled_task_failure_telegram_notifications',
        'docker_cleanup_success_telegram_notifications',
        'docker_cleanup_failure_telegram_notifications',
        'server_disk_usage_telegram_notifications',
        'server_reachable_telegram_notifications',
        'server_unreachable_telegram_notifications',
        'server_patch_telegram_notifications',
        'traefik_outdated_telegram_notifications',
    ];

    private const array THREAD_ID_FIELDS = [
        'telegram_notifications_deployment_success_thread_id',
        'telegram_notifications_deployment_failure_thread_id',
        'telegram_notifications_status_change_thread_id',
        'telegram_notifications_backup_success_thread_id',
        'telegram_notifications_backup_failure_thread_id',
        'telegram_notifications_scheduled_task_success_thread_id',
        'telegram_notifications_scheduled_task_failure_thread_id',
        'telegram_notifications_docker_cleanup_success_thread_id',
        'telegram_notifications_docker_cleanup_failure_thread_id',
        'telegram_notifications_server_disk_usage_thread_id',
        'telegram_notifications_server_reachable_thread_id',
        'telegram_notifications_server_unreachable_thread_id',
        'telegram_notifications_server_patch_thread_id',
        'telegram_notifications_traefik_outdated_thread_id',
    ];

    public function edit(): Response
    {
        $settings = currentTeam()->telegramNotificationSettings;
        $this->authorize('view', $settings);

        return Inertia::render('Notifications/Telegram', [
            'settings' => $settings->only([
                'telegram_enabled', 'telegram_token', 'telegram_chat_id',
                ...self::TOGGLE_FIELDS,
                ...self::THREAD_ID_FIELDS,
            ]),
            'updateUrl' => route('notifications.telegram.update'),
            'sendTestUrl' => route('notifications.telegram.send-test'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $settings = currentTeam()->telegramNotificationSettings;
        $this->authorize('update', $settings);

        $rules = [
            'telegram_enabled' => ['boolean'],
            'telegram_token' => ['nullable', 'string'],
            'telegram_chat_id' => ['nullable', 'string'],
        ];
        foreach (self::TOGGLE_FIELDS as $field) {
            $rules[$field] = ['boolean'];
        }
        foreach (self::THREAD_ID_FIELDS as $field) {
            $rules[$field] = ['nullable', 'string'];
        }

        $validated = Validator::make($request->all(), $rules)->validate();

        $settings->update($validated);

        return back()->with('success', 'Settings saved.');
    }

    public function sendTest(): RedirectResponse
    {
        $settings = currentTeam()->telegramNotificationSettings;
        $this->authorize('sendTest', $settings);

        currentTeam()->notify(new Test(channel: 'telegram'));

        return back()->with('success', 'Test notification sent.');
    }
}
