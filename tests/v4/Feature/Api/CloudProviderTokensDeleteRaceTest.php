<?php

declare(strict_types=1);

use App\Models\CloudProviderToken;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

it('deletes a token with no attached servers inside the locked transaction', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $cloudToken = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'digitalocean', 'token' => 'dop_v1_test', 'name' => 'Test Token']);
    $apiToken = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($apiToken))
        ->deleteJson("/api/v1/cloud-tokens/{$cloudToken->uuid}");

    $response->assertOk();
    expect(CloudProviderToken::find($cloudToken->id))->toBeNull();
});

it('blocks deleting a token that already has an attached server', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $cloudToken = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'digitalocean', 'token' => 'dop_v1_test', 'name' => 'Test Token']);
    Server::factory()->create(['team_id' => $team->id, 'cloud_provider_token_id' => $cloudToken->id]);
    $apiToken = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($apiToken))
        ->deleteJson("/api/v1/cloud-tokens/{$cloudToken->uuid}");

    $response->assertStatus(400);
    expect(CloudProviderToken::find($cloudToken->id))->not->toBeNull();
});
