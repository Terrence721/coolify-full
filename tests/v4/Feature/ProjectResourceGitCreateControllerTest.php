<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

/**
 * Helpers are prefixed per this migration's established convention to avoid Pest's
 * global-function-name collision across test files.
 */
function gitCreateTestChain(Team $team): array
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = $server->standaloneDockers()->first();

    return [$project, $environment, $server, $destination];
}

function gitCreateActingAs(Team $team): void
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);
}

function gitCreatePublicGithubSource(int $teamId): GithubApp
{
    return GithubApp::create([
        'team_id' => $teamId,
        'name' => 'Public GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => true,
    ]);
}

/**
 * Throwaway RSA key generated per call — PrivateKey::booted() rejects anything that isn't
 * real key material.
 */
function gitCreateThrowawayKey(int $teamId, string $name = 'deploy-key'): PrivateKey
{
    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $rsaKey);

    return PrivateKey::create([
        'name' => $name,
        'private_key' => $rsaKey,
        'team_id' => $teamId,
    ]);
}

/**
 * A private GitHub App whose installation-token dance (zen time check + JWT signed with a real
 * RSA key + access_tokens exchange) can run for real against Http::fake.
 */
function gitCreatePrivateGithubApp(int $teamId): GithubApp
{
    $privateKey = gitCreateThrowawayKey($teamId, 'github-app-key');

    return GithubApp::create([
        'team_id' => $teamId,
        'name' => 'my-private-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => false,
        'app_id' => 123,
        'installation_id' => 456,
        'private_key_id' => $privateKey->id,
    ]);
}

function gitCreateTokenFakes(): array
{
    return [
        'api.github.com/zen' => Http::response('Keep it logically awesome.', 200, ['date' => now()->toRfc7231String()]),
        'api.github.com/app/installations/456/access_tokens' => Http::response(['token' => 'ghs_test'], 201),
    ];
}

function gitCreateFakeRepositories(int $count, int $startId = 1): array
{
    return collect(range($startId, $startId + $count - 1))->map(fn (int $i) => [
        'id' => $i,
        'name' => "repo-{$i}",
        'owner' => ['login' => 'the-owner'],
    ])->all();
}

// ---------------------------------------------------------------------------
// index()
// ---------------------------------------------------------------------------

it('renders the public repository page', function () {
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment, $server, $destination] = gitCreateTestChain($team);

    $response = $this->get(route('project.resource.create.git', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'type' => 'public',
        'destination' => $destination->uuid,
        'server_id' => $server->id,
    ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/New/PublicGitRepository')
        ->has('checkUrl')
        ->has('submitUrl')
    );
});

it('renders the private github app page with the team github apps', function () {
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment, $server, $destination] = gitCreateTestChain($team);
    gitCreatePrivateGithubApp($team->id);

    $response = $this->get(route('project.resource.create.git', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'type' => 'private-gh-app',
        'destination' => $destination->uuid,
        'server_id' => $server->id,
    ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/New/GithubPrivateRepository')
        ->has('githubApps', 1)
        ->has('repositoriesUrl')
        ->has('branchesUrl')
        ->has('submitUrl')
    );
});

it('renders the deploy key page with the team private keys', function () {
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment, $server, $destination] = gitCreateTestChain($team);
    gitCreateThrowawayKey($team->id);

    $response = $this->get(route('project.resource.create.git', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'type' => 'private-deploy-key',
        'destination' => $destination->uuid,
        'server_id' => $server->id,
    ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/New/GithubPrivateRepositoryDeployKey')
        ->has('privateKeys', 1)
        ->has('submitUrl')
    );
});

it('redirects an unknown git type back to the resource wizard', function () {
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment] = gitCreateTestChain($team);

    $response = $this->get(route('project.resource.create.git', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'type' => 'bogus',
    ]));

    $response->assertRedirect(route('project.resource.create', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
    ]));
});

// ---------------------------------------------------------------------------
// checkPublicRepository() — including the De Morgan `.git`-suffix bug (#1) and
// the missing "Public GitHub" null guard (#3)
// ---------------------------------------------------------------------------

it('checks a github.com repository branch against the github api', function () {
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment] = gitCreateTestChain($team);
    gitCreatePublicGithubSource($team->id);

    Http::fake([
        'api.github.com/repos/coollabsio/coolify-examples/branches/v4.x' => Http::response(
            ['name' => 'v4.x'],
            200,
            ['X-RateLimit-Remaining' => '59', 'X-RateLimit-Reset' => (string) now()->addHour()->timestamp],
        ),
    ]);

    $response = $this->postJson(route('project.resource.create.git.check', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
    ]), [
        'repository_url' => 'https://github.com/coollabsio/coolify-examples/tree/v4.x',
    ]);

    $response->assertOk();
    $response->assertJson([
        'branchFound' => true,
        'branch' => 'v4.x',
        'isGithub' => true,
    ]);
    expect($response->json('rateLimitRemaining'))->toBe('59');
});

it('does not append .git to a git.sr.ht repository url', function () {
    // Pre-existing De Morgan bug: `(!contains github.com || !contains git.sr.ht)` is always
    // true, so `.git` was appended to sourcehut URLs the condition meant to exclude.
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment] = gitCreateTestChain($team);

    $response = $this->postJson(route('project.resource.create.git.check', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
    ]), [
        'repository_url' => 'https://git.sr.ht/someuser/some-repo',
    ]);

    $response->assertOk();
    expect($response->json('repositoryUrl'))->toBe('https://git.sr.ht/someuser/some-repo');
    expect($response->json('branchFound'))->toBeTrue();
});

it('appends .git to other non-github https urls', function () {
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment] = gitCreateTestChain($team);

    $response = $this->postJson(route('project.resource.create.git.check', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
    ]), [
        'repository_url' => 'https://gitea.example.com/owner/repo',
    ]);

    $response->assertOk();
    expect($response->json('repositoryUrl'))->toBe('https://gitea.example.com/owner/repo.git');
    expect($response->json('branchFound'))->toBeTrue();
});

it('survives a github.com url when no Public GitHub source row exists', function () {
    // Pre-existing bug: `GithubApp::where('name', 'Public GitHub')->first()` had no null
    // guard before `->getMorphClass()` / `->id` access.
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment] = gitCreateTestChain($team);

    $response = $this->postJson(route('project.resource.create.git.check', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
    ]), [
        'repository_url' => 'https://github.com/owner/repo',
    ]);

    $response->assertOk();
    expect($response->json('branchFound'))->toBeTrue();
});

it('falls back to an assumed branch when the github api call fails', function () {
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment] = gitCreateTestChain($team);
    gitCreatePublicGithubSource($team->id);

    Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

    $response = $this->postJson(route('project.resource.create.git.check', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
    ]), [
        'repository_url' => 'https://github.com/owner/repo',
    ]);

    $response->assertOk();
    $response->assertJson(['branchFound' => true, 'branch' => 'main']);
});

it('rejects an invalid repository url', function () {
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment] = gitCreateTestChain($team);

    $response = $this->postJson(route('project.resource.create.git.check', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
    ]), [
        'repository_url' => 'https://github.com/owner/repo;rm -rf /',
    ]);

    $response->assertStatus(422);
});

// ---------------------------------------------------------------------------
// loadRepositories() / loadBranches() — including the unbounded pagination
// loops (#2)
// ---------------------------------------------------------------------------

it('loads and sorts the repositories of a github app', function () {
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment] = gitCreateTestChain($team);
    $githubApp = gitCreatePrivateGithubApp($team->id);

    Http::fake([
        ...gitCreateTokenFakes(),
        'api.github.com/installation/repositories*' => Http::response([
            'total_count' => 2,
            'repositories' => [
                ['id' => 2, 'name' => 'zebra', 'owner' => ['login' => 'the-owner']],
                ['id' => 1, 'name' => 'aardvark', 'owner' => ['login' => 'the-owner']],
            ],
        ], 200),
    ]);

    $response = $this->getJson(route('project.resource.create.git.repositories', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'github_app_id' => $githubApp->id,
    ]));

    $response->assertOk();
    expect($response->json('repositories.0.name'))->toBe('aardvark');
    expect($response->json('repositories.1.name'))->toBe('zebra');
    expect($response->json('installationUrl'))->toBeString();
});

it('stops repository pagination when github returns an empty page despite a larger total count', function () {
    // Pre-existing bug: `while (count < total_count)` never terminated when a page came back
    // empty (e.g. after an upstream error) while total_count stayed larger.
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment] = gitCreateTestChain($team);
    $githubApp = gitCreatePrivateGithubApp($team->id);

    Http::fake([
        ...gitCreateTokenFakes(),
        'api.github.com/installation/repositories*' => Http::sequence()
            ->push(['total_count' => 200, 'repositories' => gitCreateFakeRepositories(100)], 200)
            ->push(['total_count' => 200, 'repositories' => []], 200),
    ]);

    $response = $this->getJson(route('project.resource.create.git.repositories', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'github_app_id' => $githubApp->id,
    ]));

    $response->assertOk();
    expect($response->json('repositories'))->toHaveCount(100);
});

it('404s when loading repositories of a github app owned by another team', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment] = gitCreateTestChain($team);
    $foreignApp = gitCreatePrivateGithubApp($otherTeam->id);

    $response = $this->getJson(route('project.resource.create.git.repositories', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'github_app_id' => $foreignApp->id,
    ]));

    $response->assertStatus(404);
});

it('loads branches sorted with main first', function () {
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment] = gitCreateTestChain($team);
    $githubApp = gitCreatePrivateGithubApp($team->id);

    Http::fake([
        ...gitCreateTokenFakes(),
        'api.github.com/repos/the-owner/repo-1/branches*' => Http::response([
            ['name' => 'develop'],
            ['name' => 'main'],
            ['name' => 'master'],
        ], 200),
    ]);

    $response = $this->getJson(route('project.resource.create.git.branches', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'github_app_id' => $githubApp->id,
        'owner' => 'the-owner',
        'repo' => 'repo-1',
    ]));

    $response->assertOk();
    expect($response->json('branches.*.name'))->toBe(['main', 'master', 'develop']);
});

it('stops branch pagination when a page fails instead of looping forever', function () {
    // Pre-existing bug: `while (total_branches_count === 100)` looped forever when a later
    // page kept failing, because the failure path never updated the count.
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment] = gitCreateTestChain($team);
    $githubApp = gitCreatePrivateGithubApp($team->id);

    $fullPage = collect(range(1, 100))->map(fn (int $i) => ['name' => "branch-{$i}"])->all();

    Http::fake([
        ...gitCreateTokenFakes(),
        'api.github.com/repos/the-owner/repo-1/branches*' => Http::sequence()
            ->push($fullPage, 200)
            ->push(['message' => 'Server Error'], 500)
            ->push(['message' => 'Server Error'], 500)
            ->push(['message' => 'Server Error'], 500),
    ]);

    $response = $this->getJson(route('project.resource.create.git.branches', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'github_app_id' => $githubApp->id,
        'owner' => 'the-owner',
        'repo' => 'repo-1',
    ]));

    $response->assertOk();
    expect($response->json('branches'))->toHaveCount(100);
});

// ---------------------------------------------------------------------------
// storePublic()
// ---------------------------------------------------------------------------

it('creates an application from a public github repository', function () {
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment, , $destination] = gitCreateTestChain($team);
    $source = gitCreatePublicGithubSource($team->id);

    $response = $this->post(route('project.resource.create.git.public', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'destination' => $destination->uuid,
    ]), [
        'repository_url' => 'https://github.com/coollabsio/coolify-examples',
        'git_branch' => 'main',
        'port' => 3000,
        'is_static' => false,
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
    ]);

    $application = Application::where('environment_id', $environment->id)->firstOrFail();
    expect($application->git_repository)->toBe('coollabsio/coolify-examples');
    expect($application->git_branch)->toBe('main');
    expect($application->source_id)->toBe($source->id);
    expect($application->build_pack)->toBe('nixpacks');
    $response->assertRedirect(route('project.application.configuration', [
        'application_uuid' => $application->uuid,
        'environment_uuid' => $environment->uuid,
        'project_uuid' => $project->uuid,
    ]));
});

it('creates a sourceless application from a non-github public repository', function () {
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment, , $destination] = gitCreateTestChain($team);

    $response = $this->post(route('project.resource.create.git.public', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'destination' => $destination->uuid,
    ]), [
        'repository_url' => 'https://gitea.example.com/owner/repo.git',
        'git_branch' => 'main',
        'port' => 3000,
        'is_static' => false,
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
    ]);

    $application = Application::where('environment_id', $environment->id)->firstOrFail();
    expect($application->git_repository)->toBe('https://gitea.example.com/owner/repo.git');
    expect($application->source_id)->toBeNull();
    $response->assertRedirect();
});

it('rejects a public repository submission with an invalid docker compose location', function () {
    // Pre-existing gap: docker_compose_location/base_directory reached Application::create
    // without validation in the private flows; all three store endpoints now validate both.
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment, , $destination] = gitCreateTestChain($team);

    $response = $this->post(route('project.resource.create.git.public', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'destination' => $destination->uuid,
    ]), [
        'repository_url' => 'https://gitea.example.com/owner/repo.git',
        'git_branch' => 'main',
        'port' => 3000,
        'is_static' => false,
        'build_pack' => 'dockercompose',
        'base_directory' => '/',
        'docker_compose_location' => 'no-leading-slash and spaces',
    ]);

    $response->assertSessionHasErrors('docker_compose_location');
    expect(Application::where('environment_id', $environment->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// storePrivateGithubApp()
// ---------------------------------------------------------------------------

it('creates an application from a private repository through a github app', function () {
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment, , $destination] = gitCreateTestChain($team);
    $githubApp = gitCreatePrivateGithubApp($team->id);

    $response = $this->post(route('project.resource.create.git.private-gh-app', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'destination' => $destination->uuid,
    ]), [
        'github_app_id' => $githubApp->id,
        'repository_id' => 987654,
        'owner' => 'the-owner',
        'repo' => 'the-repo',
        'git_branch' => 'main',
        'port' => 3000,
        'is_static' => false,
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
    ]);

    $application = Application::where('environment_id', $environment->id)->firstOrFail();
    expect($application->git_repository)->toBe('the-owner/the-repo');
    expect($application->repository_project_id)->toBe(987654);
    expect($application->source_id)->toBe($githubApp->id);
    expect($application->name)->toContain($application->uuid);
    $response->assertRedirect(route('project.application.configuration', [
        'application_uuid' => $application->uuid,
        'environment_uuid' => $environment->uuid,
        'project_uuid' => $project->uuid,
    ]));
});

it('rejects a private github app submission with a malformed owner', function () {
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment, , $destination] = gitCreateTestChain($team);
    $githubApp = gitCreatePrivateGithubApp($team->id);

    $response = $this->post(route('project.resource.create.git.private-gh-app', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'destination' => $destination->uuid,
    ]), [
        'github_app_id' => $githubApp->id,
        'repository_id' => 987654,
        'owner' => 'bad owner; rm -rf /',
        'repo' => 'the-repo',
        'git_branch' => 'main',
        'port' => 3000,
        'is_static' => false,
        'build_pack' => 'nixpacks',
    ]);

    $response->assertSessionHasErrors('owner');
    expect(Application::where('environment_id', $environment->id)->exists())->toBeFalse();
});

it('404s a private github app submission for an app owned by another team', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment, , $destination] = gitCreateTestChain($team);
    $foreignApp = gitCreatePrivateGithubApp($otherTeam->id);

    $response = $this->post(route('project.resource.create.git.private-gh-app', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'destination' => $destination->uuid,
    ]), [
        'github_app_id' => $foreignApp->id,
        'repository_id' => 987654,
        'owner' => 'the-owner',
        'repo' => 'the-repo',
        'git_branch' => 'main',
        'port' => 3000,
        'is_static' => false,
        'build_pack' => 'nixpacks',
    ]);

    $response->assertStatus(404);
    expect(Application::where('environment_id', $environment->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// storePrivateDeployKey()
// ---------------------------------------------------------------------------

it('creates an application from a private repository with a deploy key', function () {
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment, , $destination] = gitCreateTestChain($team);
    $privateKey = gitCreateThrowawayKey($team->id);

    $response = $this->post(route('project.resource.create.git.private-deploy-key', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'destination' => $destination->uuid,
    ]), [
        'private_key_id' => $privateKey->id,
        'repository_url' => 'https://gitea.example.com/owner/repo',
        'git_branch' => 'main',
        'port' => 3000,
        'is_static' => false,
        'build_pack' => 'nixpacks',
    ]);

    $application = Application::where('environment_id', $environment->id)->firstOrFail();
    expect($application->git_repository)->toBe('git@gitea.example.com:owner/repo.git');
    expect($application->private_key_id)->toBe($privateKey->id);
    expect($application->source_id)->toBeNull();
    $response->assertRedirect(route('project.application.configuration', [
        'application_uuid' => $application->uuid,
        'environment_uuid' => $environment->uuid,
        'project_uuid' => $project->uuid,
    ]));
});

it('keeps the owner/repo form and attaches the source for a github.com deploy key repository', function () {
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment, , $destination] = gitCreateTestChain($team);
    $source = gitCreatePublicGithubSource($team->id);
    $privateKey = gitCreateThrowawayKey($team->id);

    $this->post(route('project.resource.create.git.private-deploy-key', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'destination' => $destination->uuid,
    ]), [
        'private_key_id' => $privateKey->id,
        'repository_url' => 'https://github.com/owner/repo',
        'git_branch' => 'main',
        'port' => 3000,
        'is_static' => false,
        'build_pack' => 'nixpacks',
    ]);

    $application = Application::where('environment_id', $environment->id)->firstOrFail();
    expect($application->git_repository)->toBe('owner/repo');
    expect($application->source_id)->toBe($source->id);
});

it('404s a deploy key submission with a private key owned by another team', function () {
    // Ownership enforcement: the original accepted any private_key_id from the client.
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment, , $destination] = gitCreateTestChain($team);
    $foreignKey = gitCreateThrowawayKey($otherTeam->id, 'foreign');

    $response = $this->post(route('project.resource.create.git.private-deploy-key', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'destination' => $destination->uuid,
    ]), [
        'private_key_id' => $foreignKey->id,
        'repository_url' => 'https://gitea.example.com/owner/repo',
        'git_branch' => 'main',
        'port' => 3000,
        'is_static' => false,
        'build_pack' => 'nixpacks',
    ]);

    $response->assertStatus(404);
    expect(Application::where('environment_id', $environment->id)->exists())->toBeFalse();
});

it('rejects a deploy key submission with an invalid base directory', function () {
    // Pre-existing gap: base_directory had no validation rule at all in this flow.
    $team = Team::factory()->create();
    gitCreateActingAs($team);
    [$project, $environment, , $destination] = gitCreateTestChain($team);
    $privateKey = gitCreateThrowawayKey($team->id);

    $response = $this->post(route('project.resource.create.git.private-deploy-key', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'destination' => $destination->uuid,
    ]), [
        'private_key_id' => $privateKey->id,
        'repository_url' => 'https://gitea.example.com/owner/repo',
        'git_branch' => 'main',
        'port' => 3000,
        'is_static' => false,
        'build_pack' => 'dockercompose',
        'base_directory' => '../escape',
        'docker_compose_location' => '/docker-compose.yaml',
    ]);

    $response->assertSessionHasErrors('base_directory');
    expect(Application::where('environment_id', $environment->id)->exists())->toBeFalse();
});
