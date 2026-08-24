<?php

declare(strict_types=1);

use App\Models\CloudProviderToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

it('returns a graceful error instead of a 500 when the stored token fails to decrypt', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $cloudToken = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'digitalocean', 'token' => 'dop_v1_test', 'name' => 'Test Token']);
    DB::table('cloud_provider_tokens')->where('id', $cloudToken->id)->update(['token' => 'not-valid-encrypted-ciphertext']);
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))
        ->postJson("/api/v1/cloud-tokens/{$cloudToken->uuid}/validate");

    $response->assertOk();
    $response->assertJsonPath('valid', false);
});
