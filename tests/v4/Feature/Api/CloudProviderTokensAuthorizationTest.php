<?php

declare(strict_types=1);

use App\Models\CloudProviderToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

it('forbids a plain member from listing cloud provider tokens', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'digitalocean', 'token' => 'dop_v1_test', 'name' => 'Test Token']);
    $token = $this->apiToken($user, $team, ['read'], role: 'member');

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/cloud-tokens');

    $response->assertForbidden();
});

it('forbids a plain member from viewing a single cloud provider token', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $cloudToken = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'digitalocean', 'token' => 'dop_v1_test', 'name' => 'Test Token']);
    $token = $this->apiToken($user, $team, ['read'], role: 'member');

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/cloud-tokens/{$cloudToken->uuid}");

    $response->assertForbidden();
});

it('allows an admin to list and view cloud provider tokens', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $cloudToken = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'digitalocean', 'token' => 'dop_v1_test', 'name' => 'Test Token']);
    $token = $this->apiToken($user, $team, ['read'], role: 'admin');

    $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/cloud-tokens')->assertOk();
    $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/cloud-tokens/{$cloudToken->uuid}")->assertOk();
});
