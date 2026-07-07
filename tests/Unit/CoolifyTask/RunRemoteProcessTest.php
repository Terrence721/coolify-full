<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\CoolifyTask;

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\InvokedProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RunRemoteProcessTest extends TestCase
{
    use RefreshDatabase;

    private function makeActivity(string $type, array $extra = []): Activity
    {
        $activity = new Activity;
        $activity->description = json_encode([]);
        $activity->properties = collect([
            'type' => $type,
            ...$extra,
        ]);

        return $activity;
    }

    /** Invoke a protected method on $object via Reflection. */
    private function callProtected(object $object, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($object, $method))->invoke($object, ...$args);
    }

    /**
     * RunRemoteProcess::__invoke() is return-typed to the concrete
     * Illuminate\Process\ProcessResult, which PHP enforces at runtime — an interface
     * stub won't satisfy it. Its constructor takes a real Symfony Process, so run a
     * trivial local PHP command to get a genuine, already-finished result.
     */
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

    /**
     * Throwaway RSA key pair generated solely for these test fixtures (`ssh-keygen -t rsa
     * -b 2048 -m PEM -N ""`) — not a real credential, never used outside this test file.
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

    /**
     * SshMultiplexingHelper::generateSshCommand() is a plain static call (not a facade),
     * so it can't be mocked via shouldReceive() — build a real Server + PrivateKey (with
     * multiplexing disabled) and let it run for real.
     */
    private function createServerWithValidSshKey(string $uuid): Server
    {
        config(['constants.ssh.mux_enabled' => false]);
        Storage::fake('ssh-keys');

        $team = Team::factory()->create();

        $privateKey = PrivateKey::create([
            'name' => 'test-key',
            'private_key' => self::TEST_PRIVATE_KEY,
            'team_id' => $team->id,
        ]);

        // Pre-seed the fake disk so SshMultiplexingHelper::validateSshKey() sees the key
        // as already up to date and skips PrivateKey::storeInFileSystem() — that method's
        // lock-file handling uses a hardcoded production container path via raw fopen(),
        // which doesn't exist on this machine.
        Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);

        return Server::factory()->create([
            'uuid' => $uuid,
            'private_key_id' => $privateKey->id,
            'team_id' => $team->id,
        ]);
    }

    #[Test]
    public function constructor_accepts_valid_activity_types()
    {
        $activity = $this->makeActivity(ActivityTypes::INLINE->value);

        $action = new RunRemoteProcess($activity);

        $this->assertInstanceOf(RunRemoteProcess::class, $action);
    }

    #[Test]
    public function constructor_rejects_invalid_activity_type()
    {
        $activity = $this->makeActivity('invalid-type');

        $this->expectException(\RuntimeException::class);

        new RunRemoteProcess($activity);
    }

    #[Test]
    public function decode_output_returns_empty_string_for_null_activity()
    {
        $this->assertSame('', RunRemoteProcess::decodeOutput(null));
    }

    #[Test]
    public function decode_output_returns_empty_string_for_invalid_json()
    {
        $activity = new Activity;
        $activity->description = 'not-json';

        $this->assertSame('', RunRemoteProcess::decodeOutput($activity));
    }

    #[Test]
    public function decode_output_sorts_and_concatenates_output()
    {
        $activity = new Activity;
        $activity->description = json_encode([
            ['order' => 2, 'output' => 'B'],
            ['order' => 1, 'output' => 'A'],
        ]);

        $this->assertSame('AB', RunRemoteProcess::decodeOutput($activity));
    }

    #[Test]
    public function encode_output_appends_new_entry()
    {
        $activity = $this->makeActivity(ActivityTypes::INLINE->value);
        $activity->description = json_encode([]);

        $action = new RunRemoteProcess($activity);

        ApplicationDeploymentJob::$batch_counter = 5;

        $encoded = $action->encodeOutput('stdout', 'Hello');

        $decoded = json_decode($encoded, true);

        $this->assertCount(1, $decoded);
        $this->assertSame('stdout', $decoded[0]['type']);
        $this->assertSame('Hello', $decoded[0]['output']);
        $this->assertSame(5, $decoded[0]['batch']);
        $this->assertSame(1, $decoded[0]['order']);
    }

    #[Test]
    public function get_latest_counter_returns_1_when_empty()
    {
        $activity = $this->makeActivity(ActivityTypes::INLINE->value);
        $activity->description = json_encode([]);

        $action = new RunRemoteProcess($activity);

        $this->assertSame(1, $this->callProtected($action, 'getLatestCounter'));
    }

    #[Test]
    public function get_latest_counter_increments_last_order()
    {
        $activity = $this->makeActivity(ActivityTypes::INLINE->value);
        $activity->description = json_encode([
            ['order' => 3],
        ]);

        $action = new RunRemoteProcess($activity);

        $this->assertSame(4, $this->callProtected($action, 'getLatestCounter'));
    }

    #[Test]
    public function is_after_last_throttle_returns_true_initially()
    {
        $activity = $this->makeActivity(ActivityTypes::INLINE->value);
        $action = new RunRemoteProcess($activity);

        $this->assertTrue($this->callProtected($action, 'isAfterLastThrottle'));
    }

    #[Test]
    public function is_after_last_throttle_respects_interval()
    {
        $activity = $this->makeActivity(ActivityTypes::INLINE->value);
        $action = new RunRemoteProcess($activity);

        // Simulate previous write
        $ref = new \ReflectionClass($action);
        $prop = $ref->getProperty('last_write_at');
        $prop->setValue($action, 1000);

        // Simulate current time < throttle interval
        $ref2 = $ref->getProperty('current_time');
        $ref2->setValue($action, 1100);

        $this->assertFalse($this->callProtected($action, 'isAfterLastThrottle'));

        // Now simulate enough time passed
        $ref2->setValue($action, 1300);

        $this->assertTrue($this->callProtected($action, 'isAfterLastThrottle'));
    }

    #[Test]
    public function handle_output_writes_to_db_when_throttle_allows()
    {
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            $callback();
        });

        $activity = $this->makeActivity(ActivityTypes::INLINE->value);
        $activity->description = json_encode([]);

        $action = new RunRemoteProcess($activity);

        // Force throttle to allow write
        $ref = new \ReflectionClass($action);
        $prop = $ref->getProperty('last_write_at');
        $prop->setValue($action, 0);

        $this->callProtected($action, 'handleOutput', 'stdout', 'Hello');

        $this->assertStringContainsString('Hello', $activity->description);
    }

    #[Test]
    public function handle_output_skips_when_hide_from_output_true()
    {
        $activity = $this->makeActivity(ActivityTypes::INLINE->value);
        $activity->description = json_encode([]);

        $action = new RunRemoteProcess($activity, hide_from_output: true);

        $this->callProtected($action, 'handleOutput', 'stdout', 'Hello');

        $this->assertSame('[]', $activity->description);
    }

    #[Test]
    public function get_command_uses_ssh_multiplexing_helper()
    {
        $server = $this->createServerWithValidSshKey('server-123');

        $activity = $this->makeActivity(ActivityTypes::INLINE->value, [
            'server_uuid' => 'server-123',
            'command' => 'ls -la',
        ]);

        $action = new RunRemoteProcess($activity);

        $command = $this->callProtected($action, 'getCommand');

        $this->assertStringContainsString(escapeshellarg($server->user).'@'.escapeshellarg($server->ip), $command);
        $this->assertStringContainsString('ls -la', $command);
    }

    #[Test]
    public function invoke_updates_activity_and_returns_result()
    {
        $this->createServerWithValidSshKey('server-123');

        // Faked after the DB setup above, since Event::fake() replaces the real
        // dispatcher and would silently break BaseModel's auto-UUID creating hook.
        Event::fake();

        $activity = $this->makeActivity(ActivityTypes::INLINE->value, [
            'server_uuid' => 'server-123',
            'command' => 'echo hi',
        ]);

        // Mock Process::start()
        $mockProcess = $this->createStub(InvokedProcess::class);
        $mockProcess->method('id')->willReturn(999);
        $mockProcess->method('wait')->willReturn($this->mockProcessResult(0, 'OK', ''));

        Process::shouldReceive('timeout')->andReturnSelf();
        Process::shouldReceive('start')->andReturn($mockProcess);

        $action = new RunRemoteProcess($activity);

        $result = $action();

        $this->assertSame(0, $result->exitCode());
        $this->assertSame('OK', $activity->properties->get('stdout'));
        $this->assertSame(ProcessStatus::FINISHED->value, $activity->properties->get('status'));
    }

    #[Test]
    public function invoke_throws_when_exit_code_not_zero_and_ignore_errors_false()
    {
        $this->createServerWithValidSshKey('server-123');

        $activity = $this->makeActivity(ActivityTypes::INLINE->value, [
            'server_uuid' => 'server-123',
            'command' => 'bad',
        ]);

        $mockProcess = $this->createStub(InvokedProcess::class);
        $mockProcess->method('id')->willReturn(999);
        $mockProcess->method('wait')->willReturn($this->mockProcessResult(2, '', 'Error!'));

        Process::shouldReceive('timeout')->andReturnSelf();
        Process::shouldReceive('start')->andReturn($mockProcess);

        $action = new RunRemoteProcess($activity);

        $this->expectException(\RuntimeException::class);

        $action();
    }

    #[Test]
    public function invoke_does_not_throw_when_ignore_errors_true()
    {
        $this->createServerWithValidSshKey('server-123');

        $activity = $this->makeActivity(ActivityTypes::INLINE->value, [
            'server_uuid' => 'server-123',
            'command' => 'bad',
        ]);

        $mockProcess = $this->createStub(InvokedProcess::class);
        $mockProcess->method('id')->willReturn(999);
        $mockProcess->method('wait')->willReturn($this->mockProcessResult(2, '', 'Error!'));

        Process::shouldReceive('timeout')->andReturnSelf();
        Process::shouldReceive('start')->andReturn($mockProcess);

        $action = new RunRemoteProcess($activity, ignore_errors: true);

        $result = $action();

        $this->assertSame(2, $result->exitCode());
    }
}
