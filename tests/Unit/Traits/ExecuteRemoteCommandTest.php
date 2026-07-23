<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\InvokedProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Visus\Cuid2\Cuid2;

/**
 * Found via a real deployment smoke test (2026-07-23): executeCommandWithProcess()'s output
 * callback wraps $output through str()->trim() (an Illuminate\Support\Stringable), then passes
 * it straight to sanitize_utf8_text(?string $text) - a TypeError under strict_types=1 for any
 * log line that doesn't happen to hit the one branch (starts with '╔') where string
 * concatenation implicitly casts it back. In practice this crashed every real deployment on
 * its very first non-box-drawing log line - a completely untested happy path.
 */
class ExecuteRemoteCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Throwaway RSA key pair generated solely for this test fixture (`ssh-keygen -t rsa
     * -b 2048 -m PEM -N ""`) - not a real credential, never used outside this test file.
     */
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

    protected function tearDown(): void
    {
        config(['constants.ssh.mux_enabled' => true]);

        parent::tearDown();
    }

    /** Invoke a private method via Reflection. */
    private function callPrivate(object $object, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($object, $method);

        return $ref->invoke($object, ...$args);
    }

    private function mockProcessResult(int $exitCode, string $output, string $errorOutput): ProcessResult
    {
        $script = sprintf(
            'fwrite(STDOUT, %s); fwrite(STDERR, %s); exit(%d);',
            var_export($output, true),
            var_export($errorOutput, true),
            $exitCode
        );

        $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $script]);
        $process->run();

        return new ProcessResult($process);
    }

    private function buildJobWithServer(): array
    {
        config(['constants.ssh.mux_enabled' => false]);
        Storage::fake('ssh-keys');

        $team = Team::factory()->create();
        $privateKey = PrivateKey::create([
            'name' => 'test-key',
            'private_key' => self::TEST_PRIVATE_KEY,
            'team_id' => $team->id,
        ]);
        Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);

        $server = Server::factory()->create([
            'private_key_id' => $privateKey->id,
            'team_id' => $team->id,
        ]);

        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = $project->environments()->first();
        $destination = $server->standaloneDockers()->first();
        $application = Application::factory()->create([
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => StandaloneDocker::class,
        ]);

        $deploymentQueue = ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'deployment_uuid' => (string) new Cuid2,
            'status' => 'in_progress',
            'server_id' => $server->id,
            'destination_id' => $destination->id,
        ]);

        // The constructor itself resolves server/destination/application from the queue row
        // (real DB reads, not lazily deferred to handle()), so a correctly-populated queue row
        // is all that's needed - no Reflection required for those properties.
        $job = new ApplicationDeploymentJob($deploymentQueue->id);

        return [$job, $deploymentQueue];
    }

    #[Test]
    public function execute_command_with_process_does_not_crash_on_a_plain_output_line(): void
    {
        [$job] = $this->buildJobWithServer();

        $mockProcess = $this->createStub(InvokedProcess::class);
        $mockProcess->method('id')->willReturn(999);
        $mockProcess->method('wait')->willReturn($this->mockProcessResult(0, '', ''));

        Process::shouldReceive('timeout')->andReturnSelf();
        Process::shouldReceive('idleTimeout')->andReturnSelf();
        Process::shouldReceive('start')->andReturnUsing(function ($command, $callback) use ($mockProcess) {
            // Real deployment output rarely starts with the box-drawing '╔' character -
            // this is the exact shape of line that crashed every real deployment.
            $callback('out', 'Building production image.');

            return $mockProcess;
        });

        $this->callPrivate($job, 'executeCommandWithProcess', 'echo hi', false, null, false, false, false);

        $this->assertTrue(true, 'executeCommandWithProcess completed without throwing');
    }

    #[Test]
    public function execute_command_with_process_records_the_sanitized_output_in_the_deployment_logs(): void
    {
        [$job, $deploymentQueue] = $this->buildJobWithServer();

        $mockProcess = $this->createStub(InvokedProcess::class);
        $mockProcess->method('id')->willReturn(999);
        $mockProcess->method('wait')->willReturn($this->mockProcessResult(0, '', ''));

        Process::shouldReceive('timeout')->andReturnSelf();
        Process::shouldReceive('idleTimeout')->andReturnSelf();
        Process::shouldReceive('start')->andReturnUsing(function ($command, $callback) use ($mockProcess) {
            $callback('out', 'Installing dependencies...');

            return $mockProcess;
        });

        $this->callPrivate($job, 'executeCommandWithProcess', 'npm install', false, null, false, false, false);

        $deploymentQueue->refresh();
        $this->assertStringContainsString('Installing dependencies...', $deploymentQueue->logs);
    }
}
