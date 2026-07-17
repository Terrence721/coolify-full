<?php

declare(strict_types=1);

namespace Tests\Unit\CheckAndStartSentinelJob;

use App\Actions\Server\StartSentinel;
use App\Jobs\CheckAndStartSentinelJob;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Fakes\JobsRemoteProcessFake;
use Tests\TestCase;

require_once __DIR__.'/../../Support/Fakes/jobs_remote_process_overrides.php';

final class HandlesMissingContainerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        JobsRemoteProcessFake::reset();
    }

    #[Test]
    public function it_treats_a_missing_sentinel_container_as_not_running_instead_of_crashing(): void
    {
        // Regression test: when `docker inspect coolify-sentinel` fails because the
        // container doesn't exist (e.g. a freshly provisioned server), the SSH helper
        // returns null (it's declared ?string) rather than throwing, since handle() calls
        // it with $throwError = false. json_decode() is typed to require a string under
        // strict_types=1, so passing that null straight through fatally errored on every
        // server where Sentinel hadn't been started yet.
        Http::fake();
        JobsRemoteProcessFake::$output = null;

        $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);

        StartSentinel::shouldRun()->once()->andReturnNull();

        (new CheckAndStartSentinelJob($server))->handle();
    }
}
