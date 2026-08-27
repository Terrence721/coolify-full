<?php

declare(strict_types=1);

use App\Models\GithubApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

it('logs only the fields actually sent, not the full allowed-fields list', function () {
    $team = Team::factory()->create();
    $githubApp = GithubApp::create([
        'team_id' => $team->id,
        'name' => 'my-github-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
    ]);

    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    // auditLog() calls Log::channel('audit')->info(...) - a plain Log::spy() only
    // tracks calls made directly on the Log facade, not on the object channel()
    // returns, so channel() is chained back to the same mock via andReturnSelf()
    // to keep the whole call on one trackable double.
    Log::shouldReceive('channel')->with('audit')->andReturnSelf();
    $captured = null;
    Log::shouldReceive('info')->once()->andReturnUsing(function ($event, $context) use (&$captured) {
        $captured = $context;
    });

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/github-apps/{$githubApp->id}", [
        'name' => 'renamed-app',
    ]);

    $response->assertOk();
    expect($captured['changed_fields'])->toBe(['name']);
});

it('excludes secret fields from changed_fields even when they were sent', function () {
    $team = Team::factory()->create();
    $githubApp = GithubApp::create([
        'team_id' => $team->id,
        'name' => 'my-github-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
    ]);

    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    Log::shouldReceive('channel')->with('audit')->andReturnSelf();
    $captured = null;
    Log::shouldReceive('info')->once()->andReturnUsing(function ($event, $context) use (&$captured) {
        $captured = $context;
    });

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/github-apps/{$githubApp->id}", [
        'name' => 'renamed-app',
        'client_secret' => 'new-secret-value',
        'webhook_secret' => 'new-webhook-secret',
    ]);

    $response->assertOk();
    expect($captured['changed_fields'])->toBe(['name']);
});
