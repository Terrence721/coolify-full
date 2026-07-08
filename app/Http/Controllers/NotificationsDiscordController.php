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

class NotificationsDiscordController extends Controller
{
    use AuthorizesRequests;

    private const array TOGGLE_FIELDS = [
        'discord_enabled',
        'discord_ping_enabled',
        'deployment_success_discord_notifications',
        'deployment_failure_discord_notifications',
        'status_change_discord_notifications',
        'backup_success_discord_notifications',
        'backup_failure_discord_notifications',
        'scheduled_task_success_discord_notifications',
        'scheduled_task_failure_discord_notifications',
        'docker_cleanup_success_discord_notifications',
        'docker_cleanup_failure_discord_notifications',
        'server_disk_usage_discord_notifications',
        'server_reachable_discord_notifications',
        'server_unreachable_discord_notifications',
        'server_patch_discord_notifications',
        'traefik_outdated_discord_notifications',
    ];

    public function edit(): Response
    {
        $settings = currentTeam()->discordNotificationSettings;
        $this->authorize('view', $settings);

        return Inertia::render('Notifications/Discord', [
            'settings' => $settings->only([...self::TOGGLE_FIELDS, 'discord_webhook_url']),
            'updateUrl' => route('notifications.discord.update'),
            'sendTestUrl' => route('notifications.discord.send-test'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $settings = currentTeam()->discordNotificationSettings;
        $this->authorize('update', $settings);

        $rules = ['discord_webhook_url' => ['nullable', new SafeWebhookUrl]];
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
        $settings = currentTeam()->discordNotificationSettings;
        $this->authorize('sendTest', $settings);

        currentTeam()->notify(new Test(channel: 'discord'));

        return back()->with('success', 'Test notification sent.');
    }
}
