<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Application;

use App\Actions\Application\IsHorizonQueueEmpty;
use Laravel\Horizon\Contracts\JobRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IsHorizonQueueEmptyTest extends TestCase
{
    private function mockJob(string $status, array $tags): object
    {
        return (object) [
            'status' => $status,
            'payload' => json_encode(['tags' => $tags]),
        ];
    }

    #[Test]
    public function it_returns_true_when_no_jobs_are_running()
    {
        $repo = $this->createStub(JobRepository::class);
        $repo->method('getRecent')->willReturn(collect([]));

        $this->app->instance(JobRepository::class, $repo);

        $action = new IsHorizonQueueEmpty;
        $result = $action->handle();

        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_when_running_jobs_match_hostname()
    {
        $hostname = gethostname();

        $repo = $this->createStub(JobRepository::class);
        $repo->method('getRecent')->willReturn(collect([
            $this->mockJob('running', ['server:'.$hostname]),
        ]));

        $this->app->instance(JobRepository::class, $repo);

        $action = new IsHorizonQueueEmpty;
        $result = $action->handle();

        $this->assertFalse($result);
    }

    #[Test]
    public function it_ignores_completed_jobs()
    {
        $hostname = gethostname();

        $repo = $this->createStub(JobRepository::class);
        $repo->method('getRecent')->willReturn(collect([
            $this->mockJob('completed', ['server:'.$hostname]),
        ]));

        $this->app->instance(JobRepository::class, $repo);

        $action = new IsHorizonQueueEmpty;
        $result = $action->handle();

        $this->assertTrue($result);
    }

    #[Test]
    public function it_ignores_failed_jobs()
    {
        $hostname = gethostname();

        $repo = $this->createStub(JobRepository::class);
        $repo->method('getRecent')->willReturn(collect([
            $this->mockJob('failed', ['server:'.$hostname]),
        ]));

        $this->app->instance(JobRepository::class, $repo);

        $action = new IsHorizonQueueEmpty;
        $result = $action->handle();

        $this->assertTrue($result);
    }

    #[Test]
    public function it_ignores_jobs_without_tags()
    {
        $repo = $this->createStub(JobRepository::class);
        $repo->method('getRecent')->willReturn(collect([
            (object) [
                'status' => 'running',
                'payload' => json_encode(['foo' => 'bar']), // no tags
            ],
        ]));

        $this->app->instance(JobRepository::class, $repo);

        $action = new IsHorizonQueueEmpty;
        $result = $action->handle();

        $this->assertTrue($result);
    }

    #[Test]
    public function it_ignores_jobs_with_tags_not_matching_hostname()
    {
        $repo = $this->createStub(JobRepository::class);
        $repo->method('getRecent')->willReturn(collect([
            $this->mockJob('running', ['server:other-host']),
        ]));

        $this->app->instance(JobRepository::class, $repo);

        $action = new IsHorizonQueueEmpty;
        $result = $action->handle();

        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_when_multiple_jobs_and_one_matches_hostname()
    {
        $hostname = gethostname();

        $repo = $this->createStub(JobRepository::class);
        $repo->method('getRecent')->willReturn(collect([
            $this->mockJob('running', ['server:other-host']),
            $this->mockJob('running', ['server:'.$hostname]),
            $this->mockJob('completed', ['server:'.$hostname]),
        ]));

        $this->app->instance(JobRepository::class, $repo);

        $action = new IsHorizonQueueEmpty;
        $result = $action->handle();

        $this->assertFalse($result);
    }
}
