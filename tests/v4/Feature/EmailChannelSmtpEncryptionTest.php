<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Test as TestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not crash with a TypeError when smtp is enabled but smtp_encryption is null', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $team->emailNotificationSettings->update([
        'smtp_enabled' => true,
        'smtp_encryption' => null,
        'smtp_host' => '127.0.0.1',
        'smtp_port' => 1,
        'smtp_from_address' => 'coolify@example.com',
        'smtp_from_name' => 'Coolify',
    ]);

    $notification = new TestNotification($user->email, 'email');

    expect(fn () => (new EmailChannel)->send($team, $notification))
        ->not->toThrow(TypeError::class);
});
