<?php

declare(strict_types=1);

use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

// Throwaway RSA key pair generated solely for this test fixture, not a real credential.
const GITHUB_APPS_LOAD_REPOSITORIES_TEST_KEY = <<<'KEY'
-----BEGIN RSA PRIVATE KEY-----
MIIEpAIBAAKCAQEAtN47DRoydtu3Ko7p41K/oUA06pY8xLpU9wDjxEkk3C4RfACL
GAu2HCSfoB+WwW+mQTg2wu+GJQSQoi+a8w0hFbbUua+XbHVNHgBU5oVXh6eZA1Yk
zRlekfU0axAfPyVvZDhoAd+mu5UbDl9NpscMhbSpDNw3l8WS9VIt6Jnx0K4mTtCf
ZCuHitlzLQuBXQTKTpQo6jmpvRgxuCCWicR3I9NFcpaBZJVgXBz3fNB2LshCFP9l
P1TwEzsY2MxIgn5Us2+hdRO+P8LzRHksr8FjhJfldfnHidz7uIDSuU4Lp0gaXGWV
nbZza6+wOTjBagJcmz1jNT3KiqvL4QxGkQik6QIDAQABAoIBAAXUpjMF4FgKdgJ0
fm4TPTkGm1xTFlXeVeUylIixiyxEYJfOm5DdfZB8XKaN3+vIzlxR/v3wxutZlQvU
jn3vely7V05arpq2bSGehQG0VGjC2Mgb66c8xUxsCwrVMioCsVLhDfcTuEnLr1uo
+dx6lFjub2pC/u3NVq+Jkkj4f7qMB3hzbqkmeyQq/vTzB7i1ddEFyDPelIVvrxbp
wElIrlcLeJuFxQrTV/hxrgWEnvVGmB80lDA0vZ16q2uQJ/PqOZ//QWlCBIeCKD5t
3sMmlbogVSmn/hoAN3Za/amjQx5aZBNxYd+Yy7pun735DmX9aklgn/u1m2pxBvv9
0XMw+9MCgYEA2hwTYPGfOoexXwHzHjHJzDxIdAxJV1eXimleF5GYxMRD9uOUWjPc
fyqbKpJXbCHJm8Zm3EGOvpgugv8Il6T8VNGdghPFnUddbRy+EbiWUusUUPbuc/E1
BSBw2s14LTeBj/2bXyw6BvIp3yj44io2vdPrsB1+E94rZ7btcFOhEDcCgYEA1Enr
6i71QM9VLfbRg/a1NdGcv8fnwI8Q8BKGCNnGNvsO4ZK2VunN1U+Lv1IhamFpIy1w
JPGgFinngzkFszZ3Rx+t7/QgJLQG6AKgGEAGFsRqJXVI3sZtQrGkTKM6yVbF2Vi5
E2hFH695nHT5N93TFfmfVvnbHCKKyYqvCzecI98CgYEAyV6geaG7C9PZ68imCJuZ
H2oMzq/FStGBBPZRO9tdu1UlFp15C2rUScgxaDWiZyAuvhaIQxR30Po5/xGtgix+
F2VMUZslmRcZZ7LgvQW6LCYEJNhGwV7SP8B60VhgewbDJQjVWSJBFMah5/oxBsZI
siwlbv1buMYnNuNKBqn/izMCgYAv7xkT4dKC9c3X+RlJ4NT99/ya2TqdIjDC5Ivb
R8EX/QxZJtWBPn25oqJ9asAc0y34QXRHA0AQgRnDaYa99phsONz/h3ISl4vPq3gW
wa4eSe9l0dvIYameG5prq5fEipFWCFCR70NcajTdfRQg5zeYiKrP6s7sxWftJiFs
OPxKpQKBgQDHMksWTQSjunvD2/o4NYQquSXJvHP9JA7k3n7QgYBSFHmpFOY6xeri
my6RXd8RMIRj/i0/oLTtizy45BqHejnjWHMb2UvXebWHK0yHeC4WNaLaJhvH09UN
4xXL4TqipLiBPWflXdBDOIwdJ20U4Y3PNuVIhbpsWJAPQ1/IaKAryQ==
-----END RSA PRIVATE KEY-----
KEY;

function loadRepositoriesFakeAuth(): array
{
    return [
        'api.github.com/zen' => Http::response('', 200, ['Date' => now()->toRfc7231String()]),
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'fake-installation-token'], 201),
    ];
}

function loadRepositoriesMakeApp(Team $team): GithubApp
{
    $privateKey = PrivateKey::create([
        'team_id' => $team->id,
        'name' => 'github-app-key',
        'private_key' => GITHUB_APPS_LOAD_REPOSITORIES_TEST_KEY,
    ]);

    return GithubApp::create([
        'team_id' => $team->id,
        'name' => 'my-github-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 12345,
        'installation_id' => 67890,
        'private_key_id' => $privateKey->id,
    ]);
}

it('fetches a single page without paginating further', function () {
    $team = Team::factory()->create();
    $githubApp = loadRepositoriesMakeApp($team);

    Http::fake(array_merge(loadRepositoriesFakeAuth(), [
        'api.github.com/installation/repositories*' => Http::response([
            'total_count' => 2,
            'repositories' => [
                ['id' => 1, 'name' => 'repo-b'],
                ['id' => 2, 'name' => 'repo-a'],
            ],
        ], 200),
    ]));

    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['read'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/github-apps/{$githubApp->id}/repositories");

    $response->assertOk();
    $response->assertJsonPath('repositories.0.name', 'repo-a');
    $response->assertJsonPath('repositories.1.name', 'repo-b');
    expect($response->json('repositories'))->toHaveCount(2);

    Http::assertSentCount(3); // zen + token exchange + exactly 1 repositories page
});

it('concurrently fetches every remaining page once total_count reveals the real page count', function () {
    $team = Team::factory()->create();
    $githubApp = loadRepositoriesMakeApp($team);

    // 250 repos = 3 pages at 100/page. Page 1's total_count should be enough to know
    // this without ever needing a 4th, empty-page request to detect the end.
    Http::fake(array_merge(loadRepositoriesFakeAuth(), [
        'api.github.com/installation/repositories*' => function (Request $request) {
            $page = (int) ($request->data()['page'] ?? 1);
            $reposThisPage = match ($page) {
                1, 2 => array_map(fn ($i) => ['id' => $i, 'name' => sprintf('repo-%03d', $i)], range(($page - 1) * 100 + 1, $page * 100)),
                3 => array_map(fn ($i) => ['id' => $i, 'name' => sprintf('repo-%03d', $i)], range(201, 250)),
                default => [],
            };

            return Http::response([
                'total_count' => 250,
                'repositories' => $reposThisPage,
            ], 200);
        },
    ]));

    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['read'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/github-apps/{$githubApp->id}/repositories");

    $response->assertOk();
    expect($response->json('repositories'))->toHaveCount(250);
    expect($response->json('repositories.0.name'))->toBe('repo-001');
    expect($response->json('repositories.249.name'))->toBe('repo-250');

    Http::assertSentCount(5); // zen + token exchange + 3 repositories pages (1 sequential, 2 pooled)
});

it('surfaces an error from any failing page, not just the first', function () {
    $team = Team::factory()->create();
    $githubApp = loadRepositoriesMakeApp($team);

    Http::fake(array_merge(loadRepositoriesFakeAuth(), [
        'api.github.com/installation/repositories*' => function (Request $request) {
            $page = (int) ($request->data()['page'] ?? 1);
            if ($page === 1) {
                return Http::response([
                    'total_count' => 250,
                    'repositories' => array_map(fn ($i) => ['id' => $i, 'name' => "repo-{$i}"], range(1, 100)),
                ], 200);
            }
            if ($page === 2) {
                return Http::response(['message' => 'API rate limit exceeded'], 403);
            }

            return Http::response(['total_count' => 250, 'repositories' => []], 200);
        },
    ]));

    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['read'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/github-apps/{$githubApp->id}/repositories");

    $response->assertStatus(403);
    $response->assertJsonPath('message', 'API rate limit exceeded');
});

it('returns an empty list without paginating when the app has no repositories', function () {
    $team = Team::factory()->create();
    $githubApp = loadRepositoriesMakeApp($team);

    Http::fake(array_merge(loadRepositoriesFakeAuth(), [
        'api.github.com/installation/repositories*' => Http::response(['total_count' => 0, 'repositories' => []], 200),
    ]));

    $user = User::factory()->create();
    $token = $this->apiToken($user, $team, ['read'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/github-apps/{$githubApp->id}/repositories");

    $response->assertOk();
    expect($response->json('repositories'))->toBe([]);

    Http::assertSentCount(3); // zen + token exchange + exactly 1 repositories page, no pool ever started
});
