<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ContainerStatusAggregator;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerStatusAggregatorTest extends TestCase
{
    #[Test]
    public function empty_statuses_return_exited()
    {
        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromStrings(collect([]));

        $this->assertSame('exited', $result);
    }

    #[Test]
    public function negative_restart_count_is_corrected()
    {
        Log::shouldReceive('warning')->once();

        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromStrings(collect(['running:healthy']), -5);

        $this->assertSame('running:healthy', $result);
    }

    #[Test]
    public function degraded_status_has_highest_priority()
    {
        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromStrings(collect(['degraded:unhealthy', 'running:healthy']));

        $this->assertSame('degraded:unhealthy', $result);
    }

    #[Test]
    public function restarting_returns_restarting_unknown_when_preserved()
    {
        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromStrings(collect(['restarting']), 0, true);

        $this->assertSame('restarting:unknown', $result);
    }

    #[Test]
    public function restarting_returns_degraded_unhealthy_when_not_preserved()
    {
        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromStrings(collect(['restarting']), 0, false);

        $this->assertSame('degraded:unhealthy', $result);
    }

    #[Test]
    public function crash_loop_detected_when_exited_and_restart_count_gt_zero()
    {
        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromStrings(collect(['exited']), 3);

        $this->assertSame('degraded:unhealthy', $result);
    }

    #[Test]
    public function mixed_running_and_exited_is_degraded()
    {
        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromStrings(collect(['running:healthy', 'exited']));

        $this->assertSame('degraded:unhealthy', $result);
    }

    #[Test]
    public function mixed_running_and_starting_is_starting_unknown()
    {
        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromStrings(collect(['running:healthy', 'starting']));

        $this->assertSame('starting:unknown', $result);
    }

    #[Test]
    public function running_unhealthy_is_running_unhealthy()
    {
        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromStrings(collect(['running:unhealthy']));

        $this->assertSame('running:unhealthy', $result);
    }

    #[Test]
    public function running_unknown_is_running_unknown()
    {
        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromStrings(collect(['running:unknown']));

        $this->assertSame('running:unknown', $result);
    }

    #[Test]
    public function running_healthy_is_running_healthy()
    {
        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromStrings(collect(['running:healthy']));

        $this->assertSame('running:healthy', $result);
    }

    #[Test]
    public function dead_or_removing_is_degraded()
    {
        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromStrings(collect(['dead']));

        $this->assertSame('degraded:unhealthy', $result);
    }

    #[Test]
    public function paused_is_paused_unknown()
    {
        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromStrings(collect(['paused']));

        $this->assertSame('paused:unknown', $result);
    }

    #[Test]
    public function starting_is_starting_unknown()
    {
        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromStrings(collect(['starting']));

        $this->assertSame('starting:unknown', $result);
    }

    #[Test]
    public function created_is_starting_unknown()
    {
        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromStrings(collect(['created']));

        $this->assertSame('starting:unknown', $result);
    }

    #[Test]
    public function exited_is_exited()
    {
        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromStrings(collect(['exited']));

        $this->assertSame('exited', $result);
    }

    // -----------------------------
    // aggregateFromContainers tests
    // -----------------------------

    #[Test]
    public function containers_empty_returns_exited()
    {
        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromContainers(collect([]));

        $this->assertSame('exited', $result);
    }

    #[Test]
    public function container_running_healthy()
    {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'running',
                    'Health' => (object) ['Status' => 'healthy'],
                ],
            ],
        ]);

        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromContainers($containers);

        $this->assertSame('running:healthy', $result);
    }

    #[Test]
    public function container_running_unhealthy()
    {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'running',
                    'Health' => (object) ['Status' => 'unhealthy'],
                ],
            ],
        ]);

        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromContainers($containers);

        $this->assertSame('running:unhealthy', $result);
    }

    #[Test]
    public function container_running_unknown()
    {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'running',
                    'Health' => null,
                ],
            ],
        ]);

        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromContainers($containers);

        $this->assertSame('running:unknown', $result);
    }

    #[Test]
    public function container_restarting_preserved()
    {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'restarting',
                ],
            ],
        ]);

        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromContainers($containers, 0, true);

        $this->assertSame('restarting:unknown', $result);
    }

    #[Test]
    public function container_restarting_not_preserved()
    {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'restarting',
                ],
            ],
        ]);

        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromContainers($containers, 0, false);

        $this->assertSame('degraded:unhealthy', $result);
    }

    #[Test]
    public function container_dead_is_degraded()
    {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'dead',
                ],
            ],
        ]);

        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromContainers($containers);

        $this->assertSame('degraded:unhealthy', $result);
    }

    #[Test]
    public function container_paused_is_paused_unknown()
    {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'paused',
                ],
            ],
        ]);

        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromContainers($containers);

        $this->assertSame('paused:unknown', $result);
    }

    #[Test]
    public function container_starting_is_starting_unknown()
    {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'starting',
                ],
            ],
        ]);

        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromContainers($containers);

        $this->assertSame('starting:unknown', $result);
    }

    #[Test]
    public function container_created_is_starting_unknown()
    {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'created',
                ],
            ],
        ]);

        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromContainers($containers);

        $this->assertSame('starting:unknown', $result);
    }

    #[Test]
    public function container_exited_is_exited()
    {
        $containers = collect([
            (object) [
                'State' => (object) [
                    'Status' => 'exited',
                ],
            ],
        ]);

        $agg = new ContainerStatusAggregator;
        $result = $agg->aggregateFromContainers($containers);

        $this->assertSame('exited', $result);
    }
}
