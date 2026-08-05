<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * application_previews.application_id has no DB foreign-key constraint (see its create
 * migration - a plain foreignId(), never ->constrained()). Application::forceDeleting only
 * soft-deletes its previews before the application row itself is permanently removed, so a
 * preview can genuinely outlive its parent - reachable via CleanupStuckedResources's own
 * second preview-cleanup loop, which (unlike its sibling loop right above it) dispatches
 * DeleteResourceJob for every trashed preview with no orphan guard.
 *
 * Both DeleteResourceJob::deleteApplicationPreview() and ApplicationPreview's own
 * forceDeleting boot hook chained $preview->application->destination->server with no
 * null-safety - reproduced directly via a real handle() call (not mocked): Laravel's
 * HandleExceptions bootstrapper converts the resulting PHP warning into a real ErrorException
 * in the full app context every queue worker actually runs under (confirmed via this test
 * suite's own TestCase bootstrap; a quick tinker check gave a false negative, since tinker's
 * REPL doesn't apply that same conversion).
 */
class DeleteResourceJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_does_not_crash_when_the_previews_parent_application_no_longer_exists()
    {
        $team = Team::factory()->create();
        $server = Server::factory()->create(['team_id' => $team->id]);
        $destination = $server->standaloneDockers()->first();
        $application = Application::factory()->create([
            'destination_id' => $destination->id,
            'destination_type' => StandaloneDocker::class,
        ]);

        $preview = ApplicationPreview::create([
            'application_id' => $application->id,
            'pull_request_id' => 42,
            'pull_request_html_url' => 'https://github.com/example/repo/pull/42',
        ]);

        // Real orphaning path, not a raw DB hack.
        $application->forceDelete();
        $preview->refresh();
        $this->assertTrue($preview->trashed());

        $job = new DeleteResourceJob($preview);
        $job->handle();

        $this->assertNull(ApplicationPreview::withTrashed()->find($preview->id));
    }
}
