<?php

declare(strict_types=1);

namespace Tests\Unit\Models\ApplicationPreview;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServerAccessorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function server_resolves_through_the_parent_applications_destination()
    {
        // Regression test: DeleteResourceJob::handle()'s finally block resolves the docker-cleanup
        // target server via data_get($resource, 'server') ?? data_get($resource, 'destination.server')
        // - a pattern every other deletable resource type (Application, Service,
        // StandaloneDatabaseInstance) satisfies via a real destination()/server() relation.
        // ApplicationPreview has neither (it shares its parent Application's destination, not its
        // own), so both data_get() calls silently resolved to null and CleanupDocker::dispatch()
        // was never called for preview deletions - reached on every "PR closed" GitHub webhook.
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

        $this->assertTrue($preview->server->is($server));

        // The exact call DeleteResourceJob's finally block makes - proves the real regression is
        // fixed, not just that the accessor exists.
        $this->assertSame($server->id, data_get($preview, 'server')?->id);
    }

    #[Test]
    public function server_returns_null_instead_of_crashing_when_the_application_relation_is_missing()
    {
        $preview = new ApplicationPreview([
            'pull_request_id' => 42,
            'pull_request_html_url' => 'https://github.com/example/repo/pull/42',
        ]);
        $preview->setRelation('application', null);

        $this->assertNull($preview->server);
    }
}
