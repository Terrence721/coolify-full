<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for a real bug found while chasing an unrelated crash (StopApplication/
 * StopDatabase/StopService's soft-deleted-server fix): Server::findCached()'s identity map cache
 * is invalidated on the 'updated' model event, but SoftDeletes writes deleted_at via a direct
 * query-builder update (runSoftDelete()), not through save()/performUpdate() - so soft-deleting a
 * server never fired 'updated' and never flushed the cache. Any code that had already loaded that
 * Server via findCached() (e.g. StandaloneDocker::getServerAttribute(), the only real consumer)
 * kept being served the stale, pre-delete instance for the rest of the process, even though a
 * fresh, correctly-scoped query for the same ID would return null. Fixed by also flushing the
 * cache on the 'deleted' event (which SoftDeletes does fire, for both soft and force deletes).
 */
class ServerIdentityMapTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_stops_serving_a_stale_cached_instance_after_the_server_is_deleted(): void
    {
        $team = Team::factory()->create();
        $server = Server::factory()->create(['team_id' => $team->id]);
        $destination = $server->standaloneDockers()->first();

        // Populate the identity map cache before deleting, matching how a single request/job
        // that reads a resource's server before deleting it would behave.
        $this->assertInstanceOf(Server::class, Server::findCached($server->id));
        $this->assertInstanceOf(Server::class, $destination->server);

        $server->delete();

        $this->assertNull(Server::findCached($server->id));
        $this->assertNull($destination->refresh()->server);
    }

    #[Test]
    public function it_still_caches_normally_when_nothing_was_deleted(): void
    {
        $team = Team::factory()->create();
        $server = Server::factory()->create(['team_id' => $team->id]);

        $first = Server::findCached($server->id);
        $second = Server::findCached($server->id);

        // Same cached instance both times (not a fresh query per call).
        $this->assertSame($first, $second);
    }

    /**
     * Ensures the fix doesn't regress into flushing the identity map on every real update too -
     * that behavior already existed (the 'updated' hook) and stays a separate, working path.
     */
    #[Test]
    public function it_still_flushes_the_cache_on_a_real_update(): void
    {
        $team = Team::factory()->create();
        $server = Server::factory()->create(['team_id' => $team->id, 'name' => 'before']);

        Server::findCached($server->id);
        $server->update(['name' => 'after']);

        $this->assertSame('after', Server::findCached($server->id)->name);
    }
}
