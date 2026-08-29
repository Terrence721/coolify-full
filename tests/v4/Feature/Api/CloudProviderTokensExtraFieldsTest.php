<?php

declare(strict_types=1);

use App\Models\CloudProviderToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

it('rejects an unexpected field on create', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/v1/cloud-tokens', [
        'provider' => 'digitalocean',
        'token' => 'dop_v1_test',
        'name' => 'My Token',
        'unexpected_field' => 'nope',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('unexpected_field');
});

it('still creates a cloud provider token with only allowed fields', function () {
    Http::fake(['api.digitalocean.com/*' => Http::response(['account' => []], 200)]);
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/v1/cloud-tokens', [
        'provider' => 'digitalocean',
        'token' => 'dop_v1_test',
        'name' => 'My Token',
    ]);

    $response->assertCreated();
});

it('rejects an unexpected field on update', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $cloudToken = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'digitalocean', 'token' => 'dop_v1_test', 'name' => 'My Token']);
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/cloud-tokens/{$cloudToken->uuid}", [
        'name' => 'renamed',
        'unexpected_field' => 'nope',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('unexpected_field');
});

it('still updates a cloud provider token with only allowed fields', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $cloudToken = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'digitalocean', 'token' => 'dop_v1_test', 'name' => 'My Token']);
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/cloud-tokens/{$cloudToken->uuid}", [
        'name' => 'renamed',
    ]);

    $response->assertOk();
});
