<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

// Illuminate\Http\Request::__get() checks query/body input before falling back to the route
// parameter (Arr::get($this->all(), $key, fn () => $this->route($key))) - $request->id silently
// preferred a query-string `id` over the route's {id}, so GET /teams/{id}?id=<other> served the
// wrong team. Same bug class CloudProviderTokensController already guards against - see
// CloudProviderTokensUuidOverrideTest.php.
it('ignores a query-string id override on team_by_id()', function () {
    $user = User::factory()->create();
    $pathTeam = Team::factory()->create();
    $queryTeam = Team::factory()->create();
    $queryTeam->members()->attach($user, ['role' => 'member']);
    $token = $this->apiToken($user, $pathTeam, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))
        ->getJson("/api/v1/teams/{$pathTeam->id}?id={$queryTeam->id}");

    $response->assertOk();
    $response->assertJsonPath('id', $pathTeam->id);
});

it('ignores a query-string id override on members_by_id()', function () {
    $user = User::factory()->create();
    $pathOnlyMember = User::factory()->create();
    $pathTeam = Team::factory()->create();
    $queryTeam = Team::factory()->create();
    $pathTeam->members()->attach($pathOnlyMember, ['role' => 'member']);
    $queryTeam->members()->attach($user, ['role' => 'member']);
    $token = $this->apiToken($user, $pathTeam, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))
        ->getJson("/api/v1/teams/{$pathTeam->id}/members?id={$queryTeam->id}");

    $response->assertOk();
    $response->assertJsonFragment(['id' => $pathOnlyMember->id]);
});

it('still returns 404 for a team the caller is not a member of', function () {
    $user = User::factory()->create();
    $pathTeam = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $token = $this->apiToken($user, $pathTeam, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))
        ->getJson("/api/v1/teams/{$otherTeam->id}");

    $response->assertNotFound();
});
