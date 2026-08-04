<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
    config(['constants.webhooks.feedback_discord_webhook' => 'https://discord.com/api/webhooks/test/token']);
});

// POST /api/feedback was registered outside the versioned /api/v1/* group (and outside its
// auth:sanctum + api.token.team + api.ability middleware stack every other write action uses),
// with only a 3-req/min-per-IP throttle standing between it and relaying arbitrary
// attacker-controlled text through the operator's own server into their private Discord webhook -
// a real, reachable, unauthenticated external-relay ("confused deputy") gap once an operator sets
// FEEDBACK_DISCORD_WEBHOOK. Zero frontend code in this fork even calls this route.

it('rejects a request with no token', function () {
    Http::fake();

    $response = $this->postJson('/api/feedback', ['content' => 'this is definitely feedback']);

    $response->assertUnauthorized();
    Http::assertNothingSent();
});

it('rejects a token missing the write ability', function () {
    Http::fake();
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/feedback', ['content' => 'this is definitely feedback']);

    $response->assertForbidden();
    Http::assertNothingSent();
});

it('relays feedback to the configured Discord webhook for a valid write token', function () {
    Http::fake();
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/feedback', ['content' => 'this is definitely feedback']);

    $response->assertOk();
    Http::assertSent(function ($request) {
        return $request->url() === 'https://discord.com/api/webhooks/test/token'
            && $request->data()['content'] === 'this is definitely feedback';
    });
});
