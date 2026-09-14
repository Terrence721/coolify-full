<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\WebhookNotificationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Team::booted() creates a WebhookNotificationSettings row for every new team unconditionally -
// this is the one place a signing_secret gets generated for all of them, so a regression here
// would silently leave every new team's webhook unsigned.
it('auto-generates a signing secret when a new team is created', function () {
    $team = Team::factory()->create();

    expect($team->webhookNotificationSettings->signing_secret)
        ->toBeString()
        ->not->toBeEmpty();
});

it('does not overwrite an explicitly provided signing secret on create', function () {
    $team = Team::factory()->create();
    $team->webhookNotificationSettings->delete();

    $settings = WebhookNotificationSettings::create([
        'team_id' => $team->id,
        'signing_secret' => 'explicit-secret',
    ]);

    expect($settings->fresh()->signing_secret)->toBe('explicit-secret');
});

it('generates a different secret for each team', function () {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    expect($teamA->webhookNotificationSettings->signing_secret)
        ->not->toBe($teamB->webhookNotificationSettings->signing_secret);
});
