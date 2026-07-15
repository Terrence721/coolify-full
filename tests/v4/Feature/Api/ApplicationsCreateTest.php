<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

// Tier C (smoke): confirms each of the 5 create_*_application wrapper routes reaches
// the shared create_application() body (proof of correct delegation) via a validation
// failure. create_application()'s own 1000+ line branching logic is not characterized
// here — that's explicitly out of scope for this refactor.
dataset('applicationCreateRoutes', [
    'public' => ['/api/v1/applications/public'],
    'private-github-app' => ['/api/v1/applications/private-github-app'],
    'private-deploy-key' => ['/api/v1/applications/private-deploy-key'],
    'dockerfile' => ['/api/v1/applications/dockerfile'],
    'dockerimage' => ['/api/v1/applications/dockerimage'],
]);

it('rejects a create request missing required fields', function (string $uri) {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write']);

    // A non-empty but incomplete payload: validateIncomingRequest() 400s on a genuinely
    // empty JSON body before field validation ever runs, which isn't what this test means
    // to exercise.
    $response = $this->withHeaders($this->apiHeaders($token))->postJson($uri, ['name' => 'incomplete']);

    $response->assertStatus(422);
})->with('applicationCreateRoutes');

it('returns 404 when the project does not exist', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/v1/applications/public', [
        'project_uuid' => 'nonexistent',
        'environment_name' => 'production',
        'server_uuid' => 'nonexistent',
        'git_repository' => 'https://github.com/coollabsio/coolify.git',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
    ]);

    $response->assertNotFound();
});

it('rejects a create request for another team\'s project', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $otherTeam->id]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson('/api/v1/applications/public', [
        'project_uuid' => $project->uuid,
        'environment_name' => 'production',
        'server_uuid' => 'nonexistent',
        'git_repository' => 'https://github.com/coollabsio/coolify.git',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
    ]);

    $response->assertNotFound();
});
