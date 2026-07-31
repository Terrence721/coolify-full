<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Application;

use App\Actions\Application\IsHorizonQueueEmpty;
use Laravel\Horizon\Contracts\JobRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for a real bug found 2026-07-31 (code review, issue #70): handle() filtered
 * jobs by in_array('server:'.gethostname(), $tags) - a tag format no job in this codebase actually
 * uses (ApplicationDeploymentJob::tags() returns 'App\Models\ApplicationDeploymentQueue:<id>',
 * completely different). The filter could never match anything, so this always returned true
 * ("queue is empty") regardless of how many jobs were actually running. Fixed by dropping the
 * hostname/tag scoping entirely - Coolify is a single-instance app, so it never made sense here.
 */
class IsHorizonQueueEmptyTest extends TestCase
{
    private function mockJob(string $status, array $tags = []): object
    {
        return (object) [
            'status' => $status,
            'payload' => json_encode(['tags' => $tags]),
        ];
    }

    #[Test]
    public function it_returns_true_when_no_jobs_are_running(): void
    {
        $repo = $this->createStub(JobRepository::class);
        $repo->method('getRecent')->willReturn(collect([]));

        $this->app->instance(JobRepository::class, $repo);

        $result = IsHorizonQueueEmpty::run();

        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_for_a_running_job_regardless_of_its_tags(): void
    {
        $repo = $this->createStub(JobRepository::class);
        $repo->method('getRecent')->willReturn(collect([
            // A real job's actual tag shape (App\Models\ApplicationDeploymentQueue:<id>) - not
            // the 'server:<hostname>' format the old, broken filter required.
            $this->mockJob('running', ['App\Models\ApplicationDeploymentQueue:5']),
        ]));

        $this->app->instance(JobRepository::class, $repo);

        $result = IsHorizonQueueEmpty::run();

        $this->assertFalse($result);
    }

    #[Test]
    public function it_returns_false_for_a_running_job_with_no_tags_at_all(): void
    {
        $repo = $this->createStub(JobRepository::class);
        $repo->method('getRecent')->willReturn(collect([
            $this->mockJob('running'),
        ]));

        $this->app->instance(JobRepository::class, $repo);

        $result = IsHorizonQueueEmpty::run();

        $this->assertFalse($result);
    }

    #[Test]
    public function it_ignores_completed_jobs(): void
    {
        $repo = $this->createStub(JobRepository::class);
        $repo->method('getRecent')->willReturn(collect([
            $this->mockJob('completed'),
        ]));

        $this->app->instance(JobRepository::class, $repo);

        $result = IsHorizonQueueEmpty::run();

        $this->assertTrue($result);
    }

    #[Test]
    public function it_ignores_failed_jobs(): void
    {
        $repo = $this->createStub(JobRepository::class);
        $repo->method('getRecent')->willReturn(collect([
            $this->mockJob('failed'),
        ]));

        $this->app->instance(JobRepository::class, $repo);

        $result = IsHorizonQueueEmpty::run();

        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_when_one_of_several_jobs_is_still_running(): void
    {
        $repo = $this->createStub(JobRepository::class);
        $repo->method('getRecent')->willReturn(collect([
            $this->mockJob('completed'),
            $this->mockJob('failed'),
            $this->mockJob('running'),
        ]));

        $this->app->instance(JobRepository::class, $repo);

        $result = IsHorizonQueueEmpty::run();

        $this->assertFalse($result);
    }
}
