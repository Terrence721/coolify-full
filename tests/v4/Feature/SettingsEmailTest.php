<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the settings email Inertia page', function () {
    $user = User::forceCreate(User::factory()->raw(['id' => 0]));
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('settings.email'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('SettingsEmail')
        ->where('testEmailAddress', $user->email)
        ->where('smtpUpdateUrl', route('settings.email.update-smtp'))
        ->where('resendUpdateUrl', route('settings.email.update-resend'))
        ->where('sendTestUrl', route('settings.email.send-test'))
    );
});

it('updates the smtp settings and disables resend', function () {
    $user = User::forceCreate(User::factory()->raw(['id' => 0]));
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    InstanceSettings::get()->update(['resend_enabled' => true]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('settings.email.update-smtp'), [
            'smtp_enabled' => true,
            'smtp_from_address' => 'noreply@example.com',
            'smtp_from_name' => 'Coolify',
            'smtp_host' => 'smtp.mailgun.org',
            'smtp_port' => 587,
            'smtp_encryption' => 'starttls',
        ]);

    $response->assertRedirect();
    $settings = InstanceSettings::get();
    expect($settings->smtp_enabled)->toBeTruthy();
    expect($settings->smtp_host)->toBe('smtp.mailgun.org');
    expect($settings->resend_enabled)->toBeFalsy();
});

it('updates the resend settings and disables smtp', function () {
    $user = User::forceCreate(User::factory()->raw(['id' => 0]));
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    InstanceSettings::get()->update(['smtp_enabled' => true]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('settings.email.update-resend'), [
            'resend_enabled' => true,
            'resend_api_key' => 'resend-key',
            'smtp_from_address' => 'noreply@example.com',
            'smtp_from_name' => 'Coolify',
        ]);

    $response->assertRedirect();
    $settings = InstanceSettings::get();
    expect($settings->resend_enabled)->toBeTruthy();
    expect($settings->resend_api_key)->toBe('resend-key');
    expect($settings->smtp_enabled)->toBeFalsy();
});

it('sends a test email', function () {
    $user = User::forceCreate(User::factory()->raw(['id' => 0]));
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('settings.email.send-test'), ['test_email_address' => $user->email]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
});
