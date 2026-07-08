<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the notifications pushover Inertia page with current settings', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $team->pushoverNotificationSettings->update(['pushover_enabled' => true, 'pushover_user_key' => 'user-key', 'pushover_api_token' => 'api-token']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('notifications.pushover'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Notifications/Pushover')
        ->where('settings.pushover_enabled', true)
        ->where('settings.pushover_user_key', 'user-key')
        ->where('settings.pushover_api_token', 'api-token')
        ->where('updateUrl', route('notifications.pushover.update'))
        ->where('sendTestUrl', route('notifications.pushover.send-test'))
    );
});

it('updates pushover notification settings', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('notifications.pushover.update'), [
            'pushover_enabled' => true,
            'pushover_user_key' => 'user-key',
            'pushover_api_token' => 'api-token',
            'deployment_success_pushover_notifications' => true,
            'deployment_failure_pushover_notifications' => true,
            'status_change_pushover_notifications' => false,
            'backup_success_pushover_notifications' => false,
            'backup_failure_pushover_notifications' => true,
            'scheduled_task_success_pushover_notifications' => false,
            'scheduled_task_failure_pushover_notifications' => true,
            'docker_cleanup_success_pushover_notifications' => false,
            'docker_cleanup_failure_pushover_notifications' => true,
            'server_disk_usage_pushover_notifications' => true,
            'server_reachable_pushover_notifications' => false,
            'server_unreachable_pushover_notifications' => true,
            'server_patch_pushover_notifications' => false,
            'traefik_outdated_pushover_notifications' => true,
        ]);

    $response->assertRedirect();
    expect($team->pushoverNotificationSettings->fresh())
        ->pushover_enabled->toBeTrue()
        ->pushover_user_key->toBe('user-key')
        ->pushover_api_token->toBe('api-token');
});
