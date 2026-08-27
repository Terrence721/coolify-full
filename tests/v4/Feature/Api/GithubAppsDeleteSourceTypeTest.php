<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

it('deletes a GitHub app unused by any application, even when a GitlabApp shares its numeric id', function () {
    $team = Team::factory()->create();

    // GithubApp and GitlabApp are separate tables with independent auto-increment
    // sequences, so the very first row created in each naturally shares id=1 in a
    // fresh test database - this is the realistic collision, not a contrived one.
    $gitlabApp = GitlabApp::forceCreate([
        'team_id' => $team->id,
        'name' => 'my-gitlab-app',
        'html_url' => 'https://gitlab.com',
        'api_url' => 'https://gitlab.com/api/v4',
    ]);
    $githubApp = GithubApp::create([
        'team_id' => $team->id,
        'name' => 'my-github-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
    ]);
    expect($gitlabApp->id)->toBe($githubApp->id);

    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();

    // Real application belongs to the GitlabApp, not the GithubApp - GithubApp's own
    // correctly-scoped applications() relation (source_id + source_type) should see
    // zero applications and allow the delete.
    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'source_id' => $gitlabApp->id,
        'source_type' => GitlabApp::class,
    ]);

    expect($githubApp->applications()->count())->toBe(0);

    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/github-apps/{$githubApp->id}");

    $response->assertOk();
    expect(GithubApp::find($githubApp->id))->toBeNull();
});

it('still blocks deleting a GitHub app genuinely used by an application', function () {
    $team = Team::factory()->create();
    $githubApp = GithubApp::create([
        'team_id' => $team->id,
        'name' => 'my-github-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
    ]);

    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();

    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'source_id' => $githubApp->id,
        'source_type' => GithubApp::class,
    ]);

    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/github-apps/{$githubApp->id}");

    $response->assertStatus(409);
    expect(GithubApp::find($githubApp->id))->not->toBeNull();
});
