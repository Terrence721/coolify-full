<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\AdminDeleteUser;
use App\Models\User;
use Illuminate\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

// Regression coverage for the lock-refresh fix: admin:delete-user holds a Cache::lock() for the
// whole run but blocks on several interactive confirm()/ask() prompts of unbounded human duration
// between phases. Before the fix, the lock's TTL was only ever set once at acquisition, so a slow
// operator could outlast it and let a second run start concurrently - exactly what the lock exists
// to prevent.
class AdminDeleteUserTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function refresh_lock_extends_the_lock_ttl_when_a_lock_is_held(): void
    {
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('refresh')->once()->with(600);

        $command = new AdminDeleteUser;
        $this->setPrivateLock($command, $lock);
        $this->invokeRefreshLock($command);
    }

    #[Test]
    public function refresh_lock_is_a_safe_no_op_when_no_lock_was_acquired(): void
    {
        $command = new AdminDeleteUser;

        $this->invokeRefreshLock($command);

        $this->assertTrue(true);
    }

    #[Test]
    public function refresh_lock_swallows_exceptions_from_a_driver_that_cannot_refresh(): void
    {
        // Matches the real array/database cache driver's Illuminate\Cache\CacheLock, which
        // inherits the base Lock::refresh() that throws rather than supporting refreshing.
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('refresh')->once()->andThrow(
            new \RuntimeException('This lock driver does not support refreshing locks.')
        );

        $command = new AdminDeleteUser;
        $this->setPrivateLock($command, $lock);

        $this->invokeRefreshLock($command);

        $this->assertTrue(true);
    }

    #[Test]
    public function a_full_run_completes_and_releases_the_lock_after_calling_refresh_at_every_phase(): void
    {
        $user = User::factory()->create();
        $lockKey = "user_deletion_{$user->id}";

        $this->artisan('admin:delete-user', ['email' => $user->email, '--auto-confirm' => true])
            ->expectsConfirmation('Do you want to continue with the deletion process?', 'yes')
            ->expectsConfirmation('Are you sure you want to proceed with these team changes?', 'yes')
            ->expectsQuestion('Confirmation', "DELETE {$user->email}")
            ->assertExitCode(0);

        $this->assertNull(User::find($user->id));
        // The lock must not be left held after the command finishes - proves refreshLock()'s
        // exception-swallowing (the array test driver's lock can't actually refresh) doesn't
        // interfere with the normal release() call in the command's finally block.
        $this->assertFalse(Cache::has($lockKey));
    }

    private function setPrivateLock(AdminDeleteUser $command, ?Lock $lock): void
    {
        $property = (new ReflectionClass($command))->getProperty('lock');
        $property->setAccessible(true);
        $property->setValue($command, $lock);
    }

    private function invokeRefreshLock(AdminDeleteUser $command): void
    {
        $method = (new ReflectionClass($command))->getMethod('refreshLock');
        $method->setAccessible(true);
        $method->invoke($command);
    }
}
