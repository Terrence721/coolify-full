<?php

declare(strict_types=1);

use App\Jobs\ScheduledTaskJob;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function schedTasksActingAs(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);

    return $user;
}

function schedTasksMakeService(Team $team): Service
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->destinations()->first();

    return Service::factory()->create([
        'name' => 'test-service',
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function schedTasksParams(Service $service): array
{
    return [
        'project_uuid' => $service->environment->project->uuid,
        'environment_uuid' => $service->environment->uuid,
        'service_uuid' => $service->uuid,
    ];
}

function schedTasksMakeTask(Service $service, array $attributes = []): ScheduledTask
{
    return ScheduledTask::factory()->create([
        'name' => 'nightly-cleanup',
        'command' => 'php artisan cleanup',
        'frequency' => 'daily',
        'service_id' => $service->id,
        'team_id' => $service->team()->id,
        ...$attributes,
    ]);
}

it('renders the scheduled-tasks list tab with tasks and container names', function () {
    $team = Team::factory()->create();
    schedTasksActingAs($team);
    $service = schedTasksMakeService($team);
    ServiceApplication::create(['name' => 'app-child', 'service_id' => $service->id]);
    schedTasksMakeTask($service);

    $response = $this->get(route('project.service.scheduled-tasks.show', schedTasksParams($service)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Service/Configuration')
        ->where('tab', 'scheduled-tasks')
        ->has('tasks', 1)
        ->where('tasks.0.name', 'nightly-cleanup')
        ->where('tasks.0.lastRunStatus', 'No runs yet')
        ->where('containerNames.0', 'app-child')
        ->missing('task')
    );
});

it('renders the task detail with executions', function () {
    $team = Team::factory()->create();
    schedTasksActingAs($team);
    $service = schedTasksMakeService($team);
    $task = schedTasksMakeTask($service);
    ScheduledTaskExecution::forceCreate([
        'scheduled_task_id' => $task->id,
        'status' => 'success',
        'message' => "line one\nline two",
        'finished_at' => now(),
    ]);

    $response = $this->get(route('project.service.scheduled-tasks', [...schedTasksParams($service), 'task_uuid' => $task->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Service/Configuration')
        ->where('tab', 'scheduled-tasks')
        ->where('task.name', 'nightly-cleanup')
        ->where('task.enabled', true)
        ->has('executions', 1)
        ->where('executions.0.status', 'success')
        ->where('executions.0.message', "line one\nline two")
        ->missing('tasks')
    );
});

it('returns 404 for a task belonging to another service', function () {
    $team = Team::factory()->create();
    schedTasksActingAs($team);
    $service = schedTasksMakeService($team);
    $otherService = Service::factory()->create([
        'environment_id' => $service->environment_id,
        'server_id' => $service->server_id,
        'destination_id' => $service->destination_id,
        'destination_type' => $service->destination_type,
    ]);
    $foreignTask = schedTasksMakeTask($otherService);

    $this->get(route('project.service.scheduled-tasks', [...schedTasksParams($service), 'task_uuid' => $foreignTask->uuid]))
        ->assertNotFound();
});

it('redirects cross-team visitors to the dashboard', function () {
    $teamA = Team::factory()->create();
    $service = schedTasksMakeService($teamA);
    $task = schedTasksMakeTask($service);
    schedTasksActingAs(Team::factory()->create());

    $this->get(route('project.service.scheduled-tasks', [...schedTasksParams($service), 'task_uuid' => $task->uuid]))
        ->assertRedirect(route('dashboard'));
});

it('stores a scheduled task', function () {
    $team = Team::factory()->create();
    schedTasksActingAs($team);
    $service = schedTasksMakeService($team);

    $response = $this->post(route('project.service.scheduled-tasks.store', schedTasksParams($service)), [
        'name' => 'backup',
        'command' => 'pg_dump db',
        'frequency' => '0 0 * * *',
        'container' => 'postgres',
        'timeout' => 600,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Scheduled task added.');
    $task = $service->scheduled_tasks()->first();
    expect($task)->not->toBeNull()
        ->and($task->name)->toBe('backup')
        ->and($task->container)->toBe('postgres')
        ->and($task->timeout)->toBe(600)
        ->and($task->team_id)->toBe($team->id);
});

it('rejects an invalid cron expression on store', function () {
    $team = Team::factory()->create();
    schedTasksActingAs($team);
    $service = schedTasksMakeService($team);

    $response = $this->post(route('project.service.scheduled-tasks.store', schedTasksParams($service)), [
        'name' => 'backup',
        'command' => 'pg_dump db',
        'frequency' => 'not-a-cron',
        'timeout' => 600,
    ]);

    $response->assertSessionHas('error', 'Invalid Cron / Human expression.');
    expect($service->scheduled_tasks()->count())->toBe(0);
});

it('updates a scheduled task and trims its fields', function () {
    $team = Team::factory()->create();
    schedTasksActingAs($team);
    $service = schedTasksMakeService($team);
    $task = schedTasksMakeTask($service);

    $response = $this->patch(route('project.service.scheduled-tasks.update', [...schedTasksParams($service), 'task_uuid' => $task->uuid]), [
        'name' => '  renamed  ',
        'command' => ' echo hi ',
        'frequency' => 'hourly',
        'container' => ' app ',
        'timeout' => 120,
        'enabled' => false,
    ]);

    $response->assertSessionHas('success', 'Scheduled task updated.');
    $task->refresh();
    expect($task->name)->toBe('renamed')
        ->and($task->command)->toBe('echo hi')
        ->and($task->frequency)->toBe('hourly')
        ->and($task->container)->toBe('app')
        ->and($task->timeout)->toBe(120)
        ->and($task->enabled)->toBeFalse();
});

it('rejects an invalid cron expression on update', function () {
    $team = Team::factory()->create();
    schedTasksActingAs($team);
    $service = schedTasksMakeService($team);
    $task = schedTasksMakeTask($service);

    $response = $this->patch(route('project.service.scheduled-tasks.update', [...schedTasksParams($service), 'task_uuid' => $task->uuid]), [
        'name' => 'renamed',
        'command' => 'echo hi',
        'frequency' => 'sometimes',
        'timeout' => 120,
    ]);

    $response->assertSessionHas('error', 'Invalid Cron / Human expression.');
    expect($task->refresh()->frequency)->toBe('daily');
});

it('toggles a scheduled task', function () {
    $team = Team::factory()->create();
    schedTasksActingAs($team);
    $service = schedTasksMakeService($team);
    $task = schedTasksMakeTask($service, ['enabled' => true]);

    $this->post(route('project.service.scheduled-tasks.toggle', [...schedTasksParams($service), 'task_uuid' => $task->uuid]))
        ->assertSessionHas('success', 'Scheduled task disabled.');
    expect($task->refresh()->enabled)->toBeFalse();

    $this->post(route('project.service.scheduled-tasks.toggle', [...schedTasksParams($service), 'task_uuid' => $task->uuid]))
        ->assertSessionHas('success', 'Scheduled task enabled.');
    expect($task->refresh()->enabled)->toBeTrue();
});

it('dispatches the task job on execute now', function () {
    Queue::fake();
    $team = Team::factory()->create();
    schedTasksActingAs($team);
    $service = schedTasksMakeService($team);
    $task = schedTasksMakeTask($service);

    $response = $this->post(route('project.service.scheduled-tasks.execute', [...schedTasksParams($service), 'task_uuid' => $task->uuid]));

    $response->assertSessionHas('success', 'Scheduled task executed.');
    Queue::assertPushed(ScheduledTaskJob::class);
});

it('deletes a scheduled task after name confirmation and redirects to the list', function () {
    $team = Team::factory()->create();
    schedTasksActingAs($team);
    $service = schedTasksMakeService($team);
    $task = schedTasksMakeTask($service);

    $response = $this->delete(route('project.service.scheduled-tasks.destroy', [...schedTasksParams($service), 'task_uuid' => $task->uuid]));

    $response->assertRedirect(route('project.service.scheduled-tasks.show', schedTasksParams($service)));
    expect(ScheduledTask::find($task->id))->toBeNull();
});

it('downloads execution logs as an attachment', function () {
    $team = Team::factory()->create();
    schedTasksActingAs($team);
    $service = schedTasksMakeService($team);
    $task = schedTasksMakeTask($service);
    $execution = ScheduledTaskExecution::forceCreate([
        'scheduled_task_id' => $task->id,
        'status' => 'success',
        'message' => 'task output here',
        'finished_at' => now(),
    ]);

    $response = $this->get(route('project.service.scheduled-tasks.download', [
        ...schedTasksParams($service),
        'task_uuid' => $task->uuid,
        'execution_id' => $execution->id,
    ]));

    $response->assertOk();
    $response->assertHeader('content-disposition', 'attachment; filename=task-execution-'.$execution->id.'.log');
    expect($response->streamedContent())->toBe('task output here');
});
