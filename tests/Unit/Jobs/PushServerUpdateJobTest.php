<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\PushServerUpdateJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CallsProtectedMethods;
use Tests\TestCase;

/**
 * aggregateMultiContainerStatuses() writes an aggregated container status to whichever server
 * this push actually came from. For a multi-server application, that's only correct when
 * $this->server is the *main* destination - for an additional server it must land on that
 * server's additional_destinations pivot row instead, mirroring the main-vs-additional split
 * ComplexStatusCheck already applies. Exercised directly via CallsProtectedMethods since the
 * surrounding handle() pipeline (proxy status, log drain, ComplexStatusCheck's own real SSH
 * calls) is unrelated to this specific write-target bug.
 */
class PushServerUpdateJobTest extends TestCase
{
    use CallsProtectedMethods;
    use RefreshDatabase;

    private function createTeamServer(Team $team): Server
    {
        return Server::factory()->create(['team_id' => $team->id]);
    }

    private function createApplicationOnServer(Team $team, Server $server, array $attributes = []): Application
    {
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $destination = $server->standaloneDockers()->first();

        return Application::factory()->create([
            ...$attributes,
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => StandaloneDocker::class,
        ]);
    }

    #[Test]
    public function it_writes_to_the_main_status_column_when_this_server_is_the_applications_main_destination()
    {
        $team = Team::factory()->create();
        $mainServer = $this->createTeamServer($team);
        $application = $this->createApplicationOnServer($team, $mainServer, ['status' => 'exited:unhealthy']);

        $job = new PushServerUpdateJob($mainServer, ['containers' => []]);
        $job->applicationsById = collect([(string) $application->id => $application]);
        $job->applicationContainerStatuses = collect([
            (string) $application->id => collect(['web' => 'running:healthy']),
        ]);

        $this->callProtected($job, 'aggregateMultiContainerStatuses');

        $this->assertSame('running:healthy', $application->fresh()->status);
    }

    #[Test]
    public function it_writes_to_the_additional_servers_pivot_when_this_server_is_only_an_additional_destination()
    {
        $team = Team::factory()->create();
        $mainServer = $this->createTeamServer($team);
        $additionalServer = $this->createTeamServer($team);
        $application = $this->createApplicationOnServer($team, $mainServer, ['status' => 'exited:unhealthy']);
        $application->additional_servers()->attach($additionalServer->id, [
            'status' => 'exited:unhealthy',
            'standalone_docker_id' => $additionalServer->standaloneDockers()->first()->id,
        ]);
        $application->loadCount('additional_servers');

        $job = new PushServerUpdateJob($additionalServer, ['containers' => []]);
        $job->applicationsById = collect([(string) $application->id => $application]);
        $job->applicationContainerStatuses = collect([
            (string) $application->id => collect(['web' => 'running:healthy']),
        ]);

        $this->callProtected($job, 'aggregateMultiContainerStatuses');

        // The regression this test locks in: the main `status` column must NOT be touched by a
        // push from a non-main server - only that server's own pivot row should change. Checked
        // via the raw DB column, not the ->status accessor, since that accessor computes
        // "degraded" from a main/pivot mismatch - which is genuinely what these two values now
        // describe, not a sign the main column itself was wrongly written.
        $rawMainStatus = DB::table('applications')->where('id', $application->id)->value('status');
        $this->assertSame('exited:unhealthy', $rawMainStatus);

        $pivotStatus = DB::table('additional_destinations')
            ->where('application_id', $application->id)
            ->where('server_id', $additionalServer->id)
            ->value('status');
        $this->assertSame('running:healthy', $pivotStatus);
    }

    #[Test]
    public function it_does_nothing_when_application_container_statuses_is_empty()
    {
        $team = Team::factory()->create();
        $mainServer = $this->createTeamServer($team);
        $application = $this->createApplicationOnServer($team, $mainServer, ['status' => 'running:healthy']);

        $job = new PushServerUpdateJob($mainServer, ['containers' => []]);
        $job->applicationsById = collect([(string) $application->id => $application]);
        $job->applicationContainerStatuses = collect();

        $this->callProtected($job, 'aggregateMultiContainerStatuses');

        $this->assertSame('running:healthy', $application->fresh()->status);
    }

    #[Test]
    public function it_logs_instead_of_silently_swallowing_an_exception_while_processing_a_container()
    {
        $team = Team::factory()->create();
        $server = $this->createTeamServer($team);

        // A stand-in for an ApplicationPreview whose save() always throws - proves the real
        // failure mode: updateApplicationPreviewStatus()'s $application->save() (line 672)
        // hitting a genuine DB error (deadlock, lock-wait timeout) mid-heartbeat.
        $throwingPreview = new class
        {
            public string $status = 'exited:unhealthy';

            public function save(): void
            {
                throw new \RuntimeException('simulated DB write failure');
            }
        };

        $job = new PushServerUpdateJob($server, ['containers' => []]);
        $job->allApplicationIds = collect();
        $job->allApplicationPreviewsIds = collect(['app-1:5']);
        $job->foundApplicationPreviewsIds = collect();
        $job->applicationContainerStatuses = collect();
        $job->previewsByKey = collect(['app-1:5' => $throwingPreview]);

        Log::spy();

        $this->callProtected($job, 'processApplicationContainerLabels', collect(), 'app-1', '5', 'running:healthy');

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'processApplicationContainerLabels')
                && str_contains($context['error'] ?? '', 'simulated DB write failure'));
    }

    #[Test]
    public function it_does_not_crash_when_a_container_label_value_is_not_a_string()
    {
        // SentinelController::push() only validates 'containers' => ['present', 'array'] - nothing
        // enforces that nested label values decode as strings. Docker labels are always strings in
        // practice, but a malformed/misbehaving Sentinel payload could send a JSON number instead
        // (e.g. {"coolify.applicationId": 42}), which json-decodes to a PHP int. Under this file's
        // declare(strict_types=1), calling a string-typed parameter with a non-string throws an
        // uncaught TypeError *at the call site* in handle()'s foreach - outside any try/catch,
        // aborting the whole heartbeat's container loop instead of failing just that one container.
        // Exercised via a real handle() call (not the extracted method via reflection - Reflection
        // invocation bypasses strict_types entirely, so it wouldn't reproduce this bug) with a
        // single malformed container: the crash, if any, happens before handle() reaches any of its
        // later SSH-dependent code (updateProxyStatus() etc.), so no Process faking is needed.
        $team = Team::factory()->create();
        $server = $this->createTeamServer($team);

        $job = new PushServerUpdateJob($server, [
            'containers' => [
                [
                    'name' => 'app-container',
                    'state' => 'running',
                    'labels' => [
                        'coolify.managed' => 'true',
                        'coolify.applicationId' => 42,
                        'coolify.pullRequestId' => 0,
                        'com.docker.compose.service' => 'web',
                    ],
                ],
            ],
        ]);

        $job->handle();

        // The store keyed by applicationId only ever works correctly if the int 42 was coerced to
        // the string "42" consistently - proves the cast actually happened, not just "didn't crash".
        $this->assertSame('running:unknown', $job->applicationContainerStatuses->get('42')->get('web'));
    }
}
