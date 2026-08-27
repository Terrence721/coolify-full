<?php

declare(strict_types=1);

use App\Models\GithubApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

it('rejects an explicit null for a required field on update instead of crashing', function () {
    $team = Team::factory()->create();
    $githubApp = GithubApp::create([
        'team_id' => $team->id,
        'name' => 'my-github-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
    ]);

    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    // isset($payload['name']) is false for an explicit JSON null, so the 'name' => 'string'
    // validation rule never gets added - the null was previously written straight through
    // to update(), violating the NOT NULL `name` column and crashing uncaught (only
    // ModelNotFoundException is caught in update_github_app()).
    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/github-apps/{$githubApp->id}", [
        'name' => null,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('name');
    expect($githubApp->fresh()->name)->toBe('my-github-app');
});

it('still allows a normal rename on update', function () {
    $team = Team::factory()->create();
    $githubApp = GithubApp::create([
        'team_id' => $team->id,
        'name' => 'my-github-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
    ]);

    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/github-apps/{$githubApp->id}", [
        'name' => 'renamed-app',
    ]);

    $response->assertOk();
    expect($githubApp->fresh()->name)->toBe('renamed-app');
});

it('still accepts an explicit null for a genuinely nullable field on update', function () {
    $team = Team::factory()->create();
    $githubApp = GithubApp::create([
        'team_id' => $team->id,
        'name' => 'my-github-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'organization' => 'some-org',
    ]);

    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/github-apps/{$githubApp->id}", [
        'organization' => null,
    ]);

    $response->assertOk();
    expect($githubApp->fresh()->organization)->toBeNull();
});
