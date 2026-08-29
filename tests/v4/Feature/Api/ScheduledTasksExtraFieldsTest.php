<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledTask;
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

// Throwaway RSA key pair generated solely for this test fixture, not a real credential.
const SCHEDULED_TASKS_EXTRA_FIELDS_TEST_KEY = <<<'KEY'
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

function scheduledTasksExtraFieldsMakeApplication(Team $team): Application
{
    $privateKey = PrivateKey::create(['name' => 'throwaway-key', 'private_key' => SCHEDULED_TASKS_EXTRA_FIELDS_TEST_KEY, 'team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
}

it('rejects an unexpected field on create', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = scheduledTasksExtraFieldsMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/applications/{$application->uuid}/scheduled-tasks", [
        'name' => 'backup',
        'command' => 'echo hi',
        'frequency' => '* * * * *',
        'unexpected_field' => 'nope',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('unexpected_field');
});

it('still creates a scheduled task with only allowed fields', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = scheduledTasksExtraFieldsMakeApplication($team);
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/applications/{$application->uuid}/scheduled-tasks", [
        'name' => 'backup',
        'command' => 'echo hi',
        'frequency' => '* * * * *',
    ]);

    $response->assertCreated();
});

it('rejects an unexpected field on update', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = scheduledTasksExtraFieldsMakeApplication($team);
    $task = ScheduledTask::factory()->create([
        'application_id' => $application->id,
        'team_id' => $team->id,
    ]);
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}/scheduled-tasks/{$task->uuid}", [
        'name' => 'renamed',
        'unexpected_field' => 'nope',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('unexpected_field');
});

it('still updates a scheduled task with only allowed fields', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = scheduledTasksExtraFieldsMakeApplication($team);
    $task = ScheduledTask::factory()->create([
        'application_id' => $application->id,
        'team_id' => $team->id,
    ]);
    $token = $this->apiToken($user, $team, ['write'], role: 'admin');

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}/scheduled-tasks/{$task->uuid}", [
        'name' => 'renamed',
    ]);

    $response->assertOk();
});
