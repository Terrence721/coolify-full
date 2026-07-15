<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

it('authenticates a Sanctum bearer token through the full v1 middleware chain', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->get('/api/v1/version');

    $response->assertOk();
    $response->assertSee(config('constants.coolify.version'));
});

it('rejects a request with no token', function () {
    $response = $this->getJson('/api/v1/version');

    $response->assertUnauthorized();
});

it('rejects a token missing the required ability', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/version');

    $response->assertForbidden();
});

it('returns 403 when the instance API is disabled', function () {
    InstanceSettings::first()->update(['is_api_enabled' => false]);
    // InstanceSettings::get() memoizes via once() per-process; the update above happens
    // through a fresh query, so the cache must be flushed for the next instanceSettings()
    // call (inside the ApiAllowed middleware) to see it.
    Once::flush();
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/version');

    $response->assertForbidden();
});
