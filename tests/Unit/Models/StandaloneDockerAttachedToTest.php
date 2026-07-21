<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Found via the /tags redeploy smoke test's dev-data drift investigation: attachedTo() only
 * checked applications() and databases(), never services() - meaning DestinationController::
 * destroy() would let a destination still used by a Service be deleted through the normal UI,
 * leaving that Service's destination_id pointing at a now-nonexistent row. Fixed by adding the
 * missing services() check.
 */
class StandaloneDockerAttachedToTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_PRIVATE_KEY = <<<'KEY'
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

    protected function setUp(): void
    {
        parent::setUp();

        // Creating a StandaloneDocker fires a `created` hook that shells out to create the
        // Docker network and dispatches ConnectProxyToNetworksJob::dispatchSync($server) - both
        // need a real, resolvable Server (with a real PrivateKey, resolved before Process::fake()
        // gets a chance to short-circuit anything) to avoid crashing during test setup itself.
        config(['constants.ssh.mux_enabled' => false]);
        Storage::fake('ssh-keys');
        Process::fake();
    }

    private function makeDestination(): StandaloneDocker
    {
        $team = Team::factory()->create();
        $privateKey = PrivateKey::create([
            'name' => 'test-key',
            'private_key' => self::TEST_PRIVATE_KEY,
            'team_id' => $team->id,
        ]);
        Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
        $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);

        // Server's own `created` hook already auto-creates a default StandaloneDocker (via
        // saveQuietly(), so it doesn't double-dispatch ConnectProxyToNetworksJob) - creating a
        // second one for the same server_id would collide on the (server_id, network) unique
        // constraint, since both default to network "coolify".
        return $server->standaloneDockers()->firstOrFail();
    }

    private function makeEnvironment(): Environment
    {
        $team = Team::factory()->create();
        $project = Project::factory()->create(['team_id' => $team->id]);

        return Environment::factory()->create(['project_id' => $project->id]);
    }

    #[Test]
    public function returns_false_when_nothing_references_the_destination()
    {
        $destination = $this->makeDestination();

        $this->assertFalse($destination->attachedTo());
    }

    #[Test]
    public function returns_true_when_an_application_references_the_destination()
    {
        $destination = $this->makeDestination();
        $environment = $this->makeEnvironment();
        Application::factory()->create([
            'destination_id' => $destination->id,
            'destination_type' => StandaloneDocker::class,
            'environment_id' => $environment->id,
        ]);

        $this->assertTrue($destination->attachedTo());
    }

    #[Test]
    public function returns_true_when_a_standalone_database_references_the_destination()
    {
        $destination = $this->makeDestination();
        $environment = $this->makeEnvironment();
        StandalonePostgresql::factory()->create([
            'destination_id' => $destination->id,
            'destination_type' => StandaloneDocker::class,
            'environment_id' => $environment->id,
        ]);

        $this->assertTrue($destination->attachedTo());
    }

    #[Test]
    public function returns_true_when_a_service_references_the_destination()
    {
        $destination = $this->makeDestination();
        $environment = $this->makeEnvironment();
        Service::factory()->create([
            'destination_id' => $destination->id,
            'destination_type' => StandaloneDocker::class,
            'environment_id' => $environment->id,
        ]);

        $this->assertTrue($destination->attachedTo());
    }
}
