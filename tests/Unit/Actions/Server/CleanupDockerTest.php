<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Server;

use App\Actions\Server\CleanupDocker;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Fakes\RemoteProcessFake;
use Tests\TestCase;

require_once __DIR__.'/../../../Support/Fakes/server_action_remote_process_overrides.php';

/**
 * Regression coverage for a real bug found 2026-07-30 (code review, issue #70): the currently
 * running image was located via `docker inspect <application uuid>`, assuming the running
 * container is named exactly after the application's bare UUID. It almost never is -
 * generateApplicationContainerName() appends a deploy-time timestamp unless
 * is_consistent_application_container_name_enabled is on (off by default) - so the lookup
 * silently returned nothing, and the "protect the currently running image from deletion" logic
 * never engaged: the in-use image could be deleted like any other old image. Fixed by looking
 * the container up via Coolify's own coolify.applicationId/coolify.pullRequestId labels
 * (see defaultLabels()) instead of guessing its name.
 */
class CleanupDockerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RemoteProcessFake::reset();
        InstanceSettings::forceCreate(['id' => 0]);
    }

    protected function tearDown(): void
    {
        RemoteProcessFake::reset();
        parent::tearDown();
    }

    private function applicationOnServer(Server $server): Application
    {
        $destination = StandaloneDocker::factory()->create([
            'server_id' => $server->id,
            'network' => 'coolify-'.Str::random(8),
        ]);

        return Application::factory()->create([
            'destination_id' => $destination->id,
            'destination_type' => StandaloneDocker::class,
        ]);
    }

    #[Test]
    public function looks_up_the_running_container_by_coolify_label_not_by_guessing_its_name(): void
    {
        $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
        $application = $this->applicationOnServer($server);
        $application->settings->update(['docker_images_to_keep' => 0]);

        RemoteProcessFake::$outputQueue = [
            'v3', // currently running tag, resolved via the label-based `docker ps` lookup
            "{$application->uuid}:v3#2026-07-29 10:00:00\n{$application->uuid}:v2#2026-07-28 10:00:00\n{$application->uuid}:v1#2026-07-27 10:00:00",
        ];

        CleanupDocker::run($server);

        // Model-level side effects (e.g. StandaloneDocker's own "docker network inspect" hook)
        // share this same fake's call log, so find the lookup command by content rather than
        // assuming it's a fixed index.
        $currentTagCommand = collect(RemoteProcessFake::$instantRemoteProcessCalls)
            ->map(fn ($call) => $call[0][0])
            ->first(fn ($command) => str_starts_with($command, 'docker ps'));

        $this->assertNotNull($currentTagCommand);
        $this->assertStringContainsString("label=coolify.applicationId={$application->id}", $currentTagCommand);
        $this->assertStringNotContainsString('docker inspect', $currentTagCommand);
        $this->assertStringNotContainsString($application->uuid, $currentTagCommand);
    }

    #[Test]
    public function the_currently_running_image_tag_is_never_deleted_even_when_no_history_is_retained(): void
    {
        $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
        $application = $this->applicationOnServer($server);
        // docker_images_to_keep = 0: every non-running regular image is eligible for deletion,
        // so the only thing that can still protect the running image is the current-tag lookup.
        $application->settings->update(['docker_images_to_keep' => 0]);

        RemoteProcessFake::$outputQueue = [
            'v3',
            "{$application->uuid}:v3#2026-07-29 10:00:00\n{$application->uuid}:v2#2026-07-28 10:00:00\n{$application->uuid}:v1#2026-07-27 10:00:00",
        ];

        CleanupDocker::run($server);

        $deleteCommands = collect(RemoteProcessFake::$instantRemoteProcessCalls)
            ->map(fn ($call) => $call[0][0])
            ->filter(fn ($command) => str_starts_with($command, 'docker rmi'));

        $this->assertTrue($deleteCommands->contains(fn ($command) => str_contains($command, "{$application->uuid}:v2")));
        $this->assertTrue($deleteCommands->contains(fn ($command) => str_contains($command, "{$application->uuid}:v1")));
        $this->assertFalse($deleteCommands->contains(fn ($command) => str_contains($command, "{$application->uuid}:v3")));
    }
}
