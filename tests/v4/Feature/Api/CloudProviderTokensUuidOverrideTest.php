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

it('ignores a query-string uuid override on show()', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $pathToken = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'digitalocean', 'token' => 'dop_v1_path', 'name' => 'Path Token']);
    $queryToken = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'digitalocean', 'token' => 'dop_v1_query', 'name' => 'Query Token']);
    $token = $this->apiToken($user, $team, ['read'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))
        ->getJson("/api/v1/cloud-tokens/{$pathToken->uuid}?uuid={$queryToken->uuid}");

    $response->assertOk();
    $response->assertJsonPath('name', 'Path Token');
});

it('ignores a query-string uuid override on destroy()', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $pathToken = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'digitalocean', 'token' => 'dop_v1_path', 'name' => 'Path Token']);
    $queryToken = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'digitalocean', 'token' => 'dop_v1_query', 'name' => 'Query Token']);
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))
        ->deleteJson("/api/v1/cloud-tokens/{$pathToken->uuid}?uuid={$queryToken->uuid}");

    $response->assertOk();
    expect(CloudProviderToken::find($pathToken->id))->toBeNull();
    expect(CloudProviderToken::find($queryToken->id))->not->toBeNull();
});

it('ignores a query-string uuid override on validateToken()', function () {
    Illuminate\Support\Facades\Http::fake(['api.digitalocean.com/*' => Illuminate\Support\Facades\Http::response(['account' => []], 200)]);
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $pathToken = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'digitalocean', 'token' => 'dop_v1_path', 'name' => 'Path Token']);
    $queryToken = CloudProviderToken::create(['team_id' => $team->id, 'provider' => 'digitalocean', 'token' => 'dop_v1_query', 'name' => 'Query Token']);
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $this->withHeaders($this->apiHeaders($token))
        ->postJson("/api/v1/cloud-tokens/{$pathToken->uuid}/validate?uuid={$queryToken->uuid}")
        ->assertOk();

    Illuminate\Support\Facades\Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer dop_v1_path'));
});
