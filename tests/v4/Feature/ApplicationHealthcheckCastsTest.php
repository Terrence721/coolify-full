<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function healthcheckCastsMakeApplication(array $attrs = []): Application
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        ...$attrs,
    ]);
}

it('casts health_check_enabled to a real boolean', function () {
    $application = healthcheckCastsMakeApplication(['health_check_enabled' => false]);

    expect($application->fresh()->health_check_enabled)->toBeBool()->toBeFalse();
});

it('reports the healthcheck as disabled once health_check_enabled is a real boolean false', function () {
    $application = healthcheckCastsMakeApplication(['health_check_enabled' => false]);

    expect($application->fresh()->isHealthcheckDisabled())->toBeTrue();
});

it('reports the healthcheck as enabled when health_check_enabled is true', function () {
    $application = healthcheckCastsMakeApplication(['health_check_enabled' => true]);

    expect($application->fresh()->isHealthcheckDisabled())->toBeFalse();
});

it('casts custom_healthcheck_found to a real boolean', function () {
    $application = healthcheckCastsMakeApplication(['custom_healthcheck_found' => true]);

    expect($application->fresh()->custom_healthcheck_found)->toBeBool()->toBeTrue();
});
