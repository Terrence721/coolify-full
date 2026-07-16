<?php

declare(strict_types=1);

use App\Models\Team;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Test as TestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('silently skips sending when a team has no members to notify, matching DiscordChannel/SlackChannel', function () {
    $team = Team::factory()->create();
    // Deliberately no members attached - nothing to email.

    $notification = new TestNotification(channel: 'email');

    expect(fn () => (new EmailChannel)->send($team, $notification))->not->toThrow(Exception::class);
});
