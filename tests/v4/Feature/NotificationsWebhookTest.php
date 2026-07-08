<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the notifications webhook Inertia page with current settings', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $team->webhookNotificationSettings->update(['webhook_enabled' => true, 'webhook_url' => 'https://example.com/hook']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('notifications.webhook'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Notifications/Webhook')
        ->where('settings.webhook_enabled', true)
        ->where('settings.webhook_url', 'https://example.com/hook')
        ->where('updateUrl', route('notifications.webhook.update'))
        ->where('sendTestUrl', route('notifications.webhook.send-test'))
    );
});

it('updates webhook notification settings', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('notifications.webhook.update'), [
            'webhook_enabled' => true,
            'webhook_url' => 'https://example.com/hook',
            'deployment_success_webhook_notifications' => true,
            'deployment_failure_webhook_notifications' => true,
            'status_change_webhook_notifications' => false,
            'backup_success_webhook_notifications' => false,
            'backup_failure_webhook_notifications' => true,
            'scheduled_task_success_webhook_notifications' => false,
            'scheduled_task_failure_webhook_notifications' => true,
            'docker_cleanup_success_webhook_notifications' => false,
            'docker_cleanup_failure_webhook_notifications' => true,
            'server_disk_usage_webhook_notifications' => true,
            'server_reachable_webhook_notifications' => false,
            'server_unreachable_webhook_notifications' => true,
            'server_patch_webhook_notifications' => false,
            'traefik_outdated_webhook_notifications' => true,
        ]);

    $response->assertRedirect();
    expect($team->webhookNotificationSettings->fresh())
        ->webhook_enabled->toBeTrue()
        ->webhook_url->toBe('https://example.com/hook');
});

it('rejects an unsafe webhook url on update', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->from(route('notifications.webhook'))
        ->put(route('notifications.webhook.update'), [
            'webhook_enabled' => true,
            'webhook_url' => 'http://localhost/hook',
        ]);

    $response->assertRedirect(route('notifications.webhook'));
    $response->assertSessionHasErrors('webhook_url');
});
