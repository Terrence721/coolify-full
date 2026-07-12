<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Server;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Once;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Flushes per-process caches (Server's static identity map, Laravel's `once()`
     * memoization) before every test. Deliberately done here rather than relying on
     * a Pest beforeEach() in tests/Pest.php: a test file's own local beforeEach()
     * shadows the global one instead of composing with it, so any file with its own
     * beforeEach() (the overwhelming majority of this suite, via
     * `InstanceSettings::forceCreate(['id' => 0])`) was silently never flushing these
     * caches. That only became visible once a test mutated a Server's settings via a
     * bypassing query-builder update() and a later test reused the same auto-increment
     * ID, receiving the stale cached instance instead of its own fresh row. setUp() runs
     * unconditionally for every test regardless of what beforeEach() closures a test file
     * declares, so it can't be shadowed the same way.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Once::flush();
        Server::flushIdentityMap();
    }
}
