<?php

declare(strict_types=1);

use App\Jobs\CheckHelperImageJob;
use App\Models\InstanceSettings;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * `php artisan dev --init` is what the dev container's s6 init-setup service runs on every
 * start — it must exist (it silently didn't, for a while; see the command's docblock) and it
 * must be idempotent against an already-initialized instance.
 */
it('registers the dev command and skips seeding on an already-initialized instance', function () {
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->artisan('dev --init')->assertSuccessful();

    // InstanceSettings id 0 existed, so the seeding branch must not have run.
    expect(User::count())->toBe(0);
    Queue::assertPushed(CheckHelperImageJob::class);
});

it('marks stuck running scheduled task executions as failed', function () {
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $task = ScheduledTask::create([
        'name' => 'stuck-task',
        'command' => 'true',
        'frequency' => '* * * * *',
        'team_id' => $team->id,
        'server_id' => $server->id,
    ]);
    $execution = ScheduledTaskExecution::create([
        'scheduled_task_id' => $task->id,
        'status' => 'running',
    ]);

    $this->artisan('dev --init')->assertSuccessful();

    expect($execution->fresh()->status)->toBe('failed');
});
