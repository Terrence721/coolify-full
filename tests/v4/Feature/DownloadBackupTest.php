<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Regression coverage for a real bug (code review, issue #70): the download.backup route
 * skipped its own team-ownership check entirely whenever the caller's currently-selected team
 * was team 0 (the root team - a real, ordinary team on any self-hosted instance, not exclusive
 * to a special "root user"). Any admin/owner of team 0, with team 0 selected, could download any
 * OTHER team's real database backup by guessing a small sequential integer execution ID - a
 * genuine cross-tenant data exfiltration, not a hypothetical.
 */
beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

// A throwaway test-only key (copied from ProjectDatabaseGeneralTabTest.php's identical need - a
// different constant name to avoid a redeclaration fatal when both files load in the same run).
const DOWNLOAD_BACKUP_TEST_PRIVATE_KEY = <<<'KEY'
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

function makeBackupExecutionForTeam(Team $team, ?int $privateKeyId = null): ScheduledDatabaseBackupExecution
{
    $serverOverrides = ['team_id' => $team->id];
    if ($privateKeyId !== null) {
        $serverOverrides['private_key_id'] = $privateKeyId;
    }
    $server = Server::factory()->create($serverOverrides);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->destinations()->first();

    $database = StandalonePostgresql::create([
        'name' => 'victim-postgres',
        'postgres_password' => 'secret',
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'environment_id' => $environment->id,
        'status' => 'running',
    ]);

    $backup = $database->scheduledBackups()->create([
        'frequency' => '@daily',
        'save_s3' => false,
        'team_id' => $team->id,
    ]);

    return $backup->executions()->create([
        'status' => 'success',
        'finished_at' => now(),
        'filename' => 'backups/victim-dump.sql',
    ]);
}

it('rejects downloading another team\'s backup even when the root team (0) is currently selected', function () {
    $rootUser = User::forceCreate(User::factory()->raw(['id' => 0]));
    $rootTeam = $rootUser->teams()->first();
    $rootTeam->update(['show_boarding' => false]);

    $victimTeam = Team::factory()->create();
    $execution = makeBackupExecutionForTeam($victimTeam);

    $response = $this->actingAs($rootUser)
        ->withSession(['currentTeam' => $rootTeam])
        ->get(route('download.backup', ['executionId' => $execution->id]));

    $response->assertStatus(403);
    $response->assertJson(['message' => 'Permission denied.']);
});

it('rejects downloading another team\'s backup for an ordinary (non-root) admin too', function () {
    $user = User::factory()->create();
    $ownTeam = Team::factory()->create();
    $ownTeam->members()->attach($user, ['role' => 'admin']);

    $victimTeam = Team::factory()->create();
    $execution = makeBackupExecutionForTeam($victimTeam);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $ownTeam])
        ->get(route('download.backup', ['executionId' => $execution->id]));

    $response->assertStatus(403);
    $response->assertJson(['message' => 'Permission denied.']);
});

it('does not reject a request for a backup that actually belongs to the currently selected team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $privateKey = PrivateKey::create([
        'name' => 'test-key',
        'private_key' => DOWNLOAD_BACKUP_TEST_PRIVATE_KEY,
        'team_id' => $team->id,
    ]);
    $execution = makeBackupExecutionForTeam($team, $privateKey->id);

    // No real SFTP server is reachable in this test environment. Asserting only the generic 500
    // the route's catch-all handler returns for ANY exception can't tell "authorization passed,
    // then SFTP legitimately failed" apart from an unrelated bug thrown anywhere else in that
    // same try block - so mock Storage::build() to prove the request actually reached the
    // SFTP-connection step (past every authorization/relation-traversal check before it), then
    // throw from inside the mock to still exercise the route's real catch-all error response.
    Storage::shouldReceive('build')
        ->once()
        ->andThrow(new RuntimeException('simulated SFTP failure'));

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('download.backup', ['executionId' => $execution->id]));

    $response->assertStatus(500);
    $response->assertJson(['message' => 'Failed to download backup.']);
});
