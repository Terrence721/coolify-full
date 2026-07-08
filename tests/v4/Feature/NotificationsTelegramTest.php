<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the notifications telegram Inertia page with current settings', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $team->telegramNotificationSettings->update([
        'telegram_enabled' => true,
        'telegram_token' => 'bot-token',
        'telegram_chat_id' => 'chat-id',
        'telegram_notifications_deployment_success_thread_id' => 'thread-1',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('notifications.telegram'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Notifications/Telegram')
        ->where('settings.telegram_enabled', true)
        ->where('settings.telegram_token', 'bot-token')
        ->where('settings.telegram_chat_id', 'chat-id')
        ->where('settings.telegram_notifications_deployment_success_thread_id', 'thread-1')
        ->where('updateUrl', route('notifications.telegram.update'))
        ->where('sendTestUrl', route('notifications.telegram.send-test'))
    );
});

it('updates telegram notification settings including per-event thread ids', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('notifications.telegram.update'), [
            'telegram_enabled' => true,
            'telegram_token' => 'bot-token',
            'telegram_chat_id' => 'chat-id',
            'deployment_success_telegram_notifications' => true,
            'deployment_failure_telegram_notifications' => true,
            'status_change_telegram_notifications' => false,
            'backup_success_telegram_notifications' => false,
            'backup_failure_telegram_notifications' => true,
            'scheduled_task_success_telegram_notifications' => false,
            'scheduled_task_failure_telegram_notifications' => true,
            'docker_cleanup_success_telegram_notifications' => false,
            'docker_cleanup_failure_telegram_notifications' => true,
            'server_disk_usage_telegram_notifications' => true,
            'server_reachable_telegram_notifications' => false,
            'server_unreachable_telegram_notifications' => true,
            'server_patch_telegram_notifications' => false,
            'traefik_outdated_telegram_notifications' => true,
            'telegram_notifications_deployment_success_thread_id' => 'thread-1',
            'telegram_notifications_deployment_failure_thread_id' => null,
            'telegram_notifications_status_change_thread_id' => null,
            'telegram_notifications_backup_success_thread_id' => null,
            'telegram_notifications_backup_failure_thread_id' => null,
            'telegram_notifications_scheduled_task_success_thread_id' => null,
            'telegram_notifications_scheduled_task_failure_thread_id' => null,
            'telegram_notifications_docker_cleanup_success_thread_id' => null,
            'telegram_notifications_docker_cleanup_failure_thread_id' => null,
            'telegram_notifications_server_disk_usage_thread_id' => null,
            'telegram_notifications_server_reachable_thread_id' => null,
            'telegram_notifications_server_unreachable_thread_id' => null,
            'telegram_notifications_server_patch_thread_id' => null,
            'telegram_notifications_traefik_outdated_thread_id' => null,
        ]);

    $response->assertRedirect();
    expect($team->telegramNotificationSettings->fresh())
        ->telegram_enabled->toBeTrue()
        ->telegram_token->toBe('bot-token')
        ->telegram_chat_id->toBe('chat-id')
        ->telegram_notifications_deployment_success_thread_id->toBe('thread-1');
});
