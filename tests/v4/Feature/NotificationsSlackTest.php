<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the notifications slack Inertia page with current settings', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $team->slackNotificationSettings->update(['slack_enabled' => true, 'slack_webhook_url' => 'https://hooks.slack.com/services/1/abc']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('notifications.slack'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Notifications/Slack')
        ->where('settings.slack_enabled', true)
        ->where('settings.slack_webhook_url', 'https://hooks.slack.com/services/1/abc')
        ->where('updateUrl', route('notifications.slack.update'))
        ->where('sendTestUrl', route('notifications.slack.send-test'))
    );
});

it('updates slack notification settings', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('notifications.slack.update'), [
            'slack_enabled' => true,
            'slack_webhook_url' => 'https://hooks.slack.com/services/1/abc',
            'deployment_success_slack_notifications' => true,
            'deployment_failure_slack_notifications' => true,
            'status_change_slack_notifications' => false,
            'backup_success_slack_notifications' => false,
            'backup_failure_slack_notifications' => true,
            'scheduled_task_success_slack_notifications' => false,
            'scheduled_task_failure_slack_notifications' => true,
            'docker_cleanup_success_slack_notifications' => false,
            'docker_cleanup_failure_slack_notifications' => true,
            'server_disk_usage_slack_notifications' => true,
            'server_reachable_slack_notifications' => false,
            'server_unreachable_slack_notifications' => true,
            'server_patch_slack_notifications' => false,
            'traefik_outdated_slack_notifications' => true,
        ]);

    $response->assertRedirect();
    expect($team->slackNotificationSettings->fresh())
        ->slack_enabled->toBeTrue()
        ->slack_webhook_url->toBe('https://hooks.slack.com/services/1/abc');
});

it('rejects an unsafe slack webhook url on update', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->from(route('notifications.slack'))
        ->put(route('notifications.slack.update'), [
            'slack_enabled' => true,
            'slack_webhook_url' => 'http://localhost/hook',
        ]);

    $response->assertRedirect(route('notifications.slack'));
    $response->assertSessionHasErrors('slack_webhook_url');
});
