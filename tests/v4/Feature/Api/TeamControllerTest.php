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

it('lists only the teams the caller is a member of', function () {
    $user = User::factory()->create();
    $myTeam = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $token = $this->apiToken($user, $myTeam, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/teams');

    $response->assertOk();
    $response->assertJsonFragment(['id' => $myTeam->id]);
    $response->assertJsonMissing(['id' => $otherTeam->id]);
});

it('returns the token team on current_team()', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/teams/current');

    $response->assertOk();
    $response->assertJsonPath('id', $team->id);
});

it('returns the token team members on current_team_members()', function () {
    $user = User::factory()->create();
    $otherMember = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($otherMember, ['role' => 'member']);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/teams/current/members');

    $response->assertOk();
    $response->assertJsonFragment(['id' => $otherMember->id]);
});
