<?php

declare(strict_types=1);

use App\Enums\ProcessStatus;
use App\Http\Controllers\ProjectLogsController;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('actually persists ERROR status onto stale in-progress activities', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->destinations()->first();

    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $activity = Activity::create([
        'description' => 'service:start',
        'properties' => [
            'type_uuid' => $service->uuid,
            'status' => ProcessStatus::IN_PROGRESS->value,
        ],
    ]);

    app(ProjectLogsController::class)->markStaleServiceActivitiesAsErrored(
        $service,
        [ProcessStatus::IN_PROGRESS->value, ProcessStatus::QUEUED->value]
    );

    expect($activity->fresh()->properties->get('status'))->toBe(ProcessStatus::ERROR->value);
});
