<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the notifications discord Inertia page with current settings', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $team->discordNotificationSettings->update(['discord_enabled' => true, 'discord_webhook_url' => 'https://discord.com/api/webhooks/1/abc']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('notifications.discord'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Notifications/Discord')
        ->where('settings.discord_enabled', true)
        ->where('settings.discord_webhook_url', 'https://discord.com/api/webhooks/1/abc')
        ->where('updateUrl', route('notifications.discord.update'))
        ->where('sendTestUrl', route('notifications.discord.send-test'))
    );
});

it('updates discord notification settings', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('notifications.discord.update'), [
            'discord_enabled' => true,
            'discord_webhook_url' => 'https://discord.com/api/webhooks/1/abc',
            'discord_ping_enabled' => false,
            'deployment_success_discord_notifications' => true,
            'deployment_failure_discord_notifications' => true,
            'status_change_discord_notifications' => false,
            'backup_success_discord_notifications' => false,
            'backup_failure_discord_notifications' => true,
            'scheduled_task_success_discord_notifications' => false,
            'scheduled_task_failure_discord_notifications' => true,
            'docker_cleanup_success_discord_notifications' => false,
            'docker_cleanup_failure_discord_notifications' => true,
            'server_disk_usage_discord_notifications' => true,
            'server_reachable_discord_notifications' => false,
            'server_unreachable_discord_notifications' => true,
            'server_patch_discord_notifications' => false,
            'traefik_outdated_discord_notifications' => true,
        ]);

    $response->assertRedirect();
    expect($team->discordNotificationSettings->fresh())
        ->discord_enabled->toBeTrue()
        ->discord_webhook_url->toBe('https://discord.com/api/webhooks/1/abc');
});

it('rejects an unsafe discord webhook url on update', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->from(route('notifications.discord'))
        ->put(route('notifications.discord.update'), [
            'discord_enabled' => true,
            'discord_webhook_url' => 'http://localhost/hook',
        ]);

    $response->assertRedirect(route('notifications.discord'));
    $response->assertSessionHasErrors('discord_webhook_url');
});
