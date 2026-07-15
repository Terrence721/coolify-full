<?php

declare(strict_types=1);

use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

const API_INDEX_SHOW_TEST_RSA_KEY = <<<'KEY'
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

// ServerFactory defaults private_key_id to 1, a placeholder that only resolves in a
// dev-seeded DB, not a fresh test transaction — routes that dereference $server->privateKey
// (e.g. logs_by_uuid()) need a real row or they 404 with "No query results for model
// [PrivateKey] 1" instead of exercising the actual code under test.
function apiMakeThrowawayKey(Team $team): PrivateKey
{
    return PrivateKey::create([
        'name' => 'throwaway-key',
        'private_key' => API_INDEX_SHOW_TEST_RSA_KEY,
        'team_id' => $team->id,
    ]);
}

function apiMakeApplication(Team $team, array $attrs = []): Application
{
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => apiMakeThrowawayKey($team)->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        ...$attrs,
    ]);
}

it('lists applications owned by the token team', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    apiMakeApplication($team, ['name' => 'my-app']);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/applications');

    $response->assertOk();
    $response->assertJsonCount(1);
    $response->assertJsonFragment(['name' => 'my-app']);
});

it('does not list another team\'s applications', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    apiMakeApplication($otherTeam, ['name' => 'not-mine']);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson('/api/v1/applications');

    $response->assertOk();
    $response->assertJsonCount(0);
});

it('shows a single application by uuid', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($team);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/applications/{$application->uuid}");

    $response->assertOk();
    $response->assertJsonFragment(['uuid' => $application->uuid]);
});

it('returns 404 for an application belonging to another team', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($otherTeam);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/applications/{$application->uuid}");

    $response->assertNotFound();
});

it('updates an application field', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}", [
        'name' => 'renamed-app',
    ]);

    $response->assertOk();
    expect($application->refresh()->name)->toBe('renamed-app');
});

it('rejects an update with a non-numeric ports_exposes', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}", [
        'ports_exposes' => 'not-a-number',
    ]);

    $response->assertStatus(422);
});

it('rejects an update to another team\'s application', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($otherTeam);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}", [
        'name' => 'hijacked',
    ]);

    $response->assertNotFound();
});

it('queues an application deletion', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/applications/{$application->uuid}");

    $response->assertOk();
    Queue::assertPushed(DeleteResourceJob::class);
});

it('returns 404 when deleting another team\'s application', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($otherTeam);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/applications/{$application->uuid}");

    $response->assertNotFound();
});

it('returns 404 for logs of another team\'s application', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($otherTeam);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/applications/{$application->uuid}/logs");

    $response->assertNotFound();
});

// logs_by_uuid()'s "not running" 400 path is reached only after a real SSH round-trip
// (getCurrentApplicationContainerStatus() checks the live container list) — same untested
// SSH-touching-happy-path gap documented in docs/smoketest.md, not mocked here.

it('rejects an invalid pull_request_id when deleting a preview', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/applications/{$application->uuid}/previews/not-a-number");

    $response->assertStatus(422);
});

it('returns 404 when deleting a preview that does not exist', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = apiMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/applications/{$application->uuid}/previews/1");

    $response->assertNotFound();
});
