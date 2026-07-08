<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the notifications email Inertia page with current settings', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('notifications.email'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Notifications/Email')
        ->where('testEmailAddress', $user->email)
        ->where('updateUrl', route('notifications.email.update'))
        ->where('smtpUpdateUrl', route('notifications.email.update-smtp'))
        ->where('resendUpdateUrl', route('notifications.email.update-resend'))
        ->where('sendTestUrl', route('notifications.email.send-test'))
        ->where('copyFromInstanceUrl', route('notifications.email.copy-from-instance'))
    );
});

it('updates the main email notification settings', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('notifications.email.update'), [
            'use_instance_email_settings' => false,
            'smtp_from_name' => 'Coolify',
            'smtp_from_address' => 'coolify@example.com',
            'deployment_success_email_notifications' => true,
            'deployment_failure_email_notifications' => true,
            'status_change_email_notifications' => false,
            'backup_success_email_notifications' => false,
            'backup_failure_email_notifications' => true,
            'scheduled_task_success_email_notifications' => false,
            'scheduled_task_failure_email_notifications' => true,
            'docker_cleanup_success_email_notifications' => false,
            'docker_cleanup_failure_email_notifications' => true,
            'server_disk_usage_email_notifications' => true,
            'server_reachable_email_notifications' => false,
            'server_unreachable_email_notifications' => true,
            'server_patch_email_notifications' => false,
            'traefik_outdated_email_notifications' => true,
        ]);

    $response->assertRedirect();
    expect($team->emailNotificationSettings->fresh())
        ->smtp_from_name->toBe('Coolify')
        ->smtp_from_address->toBe('coolify@example.com');
});

it('updates smtp settings and disables resend', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $team->emailNotificationSettings->update(['resend_enabled' => true]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('notifications.email.update-smtp'), [
            'smtp_enabled' => true,
            'smtp_from_address' => 'coolify@example.com',
            'smtp_from_name' => 'Coolify',
            'smtp_host' => 'smtp.mailgun.org',
            'smtp_port' => 587,
            'smtp_encryption' => 'starttls',
        ]);

    $response->assertRedirect();
    expect($team->emailNotificationSettings->fresh())
        ->smtp_enabled->toBeTrue()
        ->smtp_host->toBe('smtp.mailgun.org')
        ->resend_enabled->toBeFalse();
});

it('updates resend settings and disables smtp', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $team->emailNotificationSettings->update(['smtp_enabled' => true]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('notifications.email.update-resend'), [
            'resend_enabled' => true,
            'resend_api_key' => 're_123',
            'smtp_from_address' => 'coolify@example.com',
            'smtp_from_name' => 'Coolify',
        ]);

    $response->assertRedirect();
    expect($team->emailNotificationSettings->fresh())
        ->resend_enabled->toBeTrue()
        ->resend_api_key->toBe('re_123')
        ->smtp_enabled->toBeFalse();
});

it('copies settings from the instance settings', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    // InstanceSettings::get() reads a singleton row hardcoded to id 0, seeded at
    // install time in real deployments but not created by any migration/factory.
    // 'id' isn't fillable, so forceCreate() is needed to set it explicitly.
    $instance = InstanceSettings::forceCreate(['id' => 0]);
    $instance->update([
        'smtp_enabled' => true,
        'smtp_from_address' => 'instance@example.com',
        'smtp_from_name' => 'Instance',
        'smtp_host' => 'smtp.instance.test',
        'smtp_port' => 2525,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('notifications.email.copy-from-instance'));

    $response->assertRedirect();
    expect($team->emailNotificationSettings->fresh())
        ->smtp_enabled->toBeTrue()
        ->smtp_from_address->toBe('instance@example.com')
        ->smtp_host->toBe('smtp.instance.test');
});
