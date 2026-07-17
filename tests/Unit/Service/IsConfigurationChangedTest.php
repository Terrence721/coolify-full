<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function isConfigurationChangedMakeService(): Service
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->destinations()->first();

    return Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
    ]);
}

it('is not considered changed when an environment variable value actually stays the same', function () {
    $service = isConfigurationChangedMakeService();
    $service->environment_variables()->create([
        'key' => 'FOO',
        'value' => 'bar',
        'resourceable_id' => $service->id,
        'resourceable_type' => $service->getMorphClass(),
        'is_preview' => false,
    ]);

    expect($service->isConfigurationChanged(save: true))->toBeTrue();
    expect($service->fresh()->isConfigurationChanged())->toBeFalse();
});

it('is considered changed when an environment variable value actually changes', function () {
    $service = isConfigurationChangedMakeService();
    $env = $service->environment_variables()->create([
        'key' => 'FOO',
        'value' => 'bar',
        'resourceable_id' => $service->id,
        'resourceable_type' => $service->getMorphClass(),
        'is_preview' => false,
    ]);

    $service->isConfigurationChanged(save: true);

    $env->update(['value' => 'changed']);

    expect($service->fresh()->isConfigurationChanged())->toBeTrue();
});

it('hashes environment variable values sorted by value, independent of creation order', function () {
    // Regression test: isConfigurationChanged() hashed $this->environment_variables()->get('value')
    // instead of ->pluck('value') - get('value') returns full EnvironmentVariable model instances,
    // and Collection::sort() on model objects does not sort by any attribute (confirmed empirically:
    // it left them in original/insertion order). So the hash was actually order-dependent despite
    // the code's clear intent to sort for determinism - the same set of values, created in a
    // different order, could hash differently and falsely report the config as "changed".
    $serviceA = isConfigurationChangedMakeService();
    $serviceA->environment_variables()->create(['key' => 'A', 'value' => 'zzz', 'resourceable_id' => $serviceA->id, 'resourceable_type' => $serviceA->getMorphClass(), 'is_preview' => false]);
    $serviceA->environment_variables()->create(['key' => 'B', 'value' => 'aaa', 'resourceable_id' => $serviceA->id, 'resourceable_type' => $serviceA->getMorphClass(), 'is_preview' => false]);
    $serviceA->isConfigurationChanged(save: true);

    $serviceB = isConfigurationChangedMakeService();
    // Same two values, opposite creation order.
    $serviceB->environment_variables()->create(['key' => 'B', 'value' => 'aaa', 'resourceable_id' => $serviceB->id, 'resourceable_type' => $serviceB->getMorphClass(), 'is_preview' => false]);
    $serviceB->environment_variables()->create(['key' => 'A', 'value' => 'zzz', 'resourceable_id' => $serviceB->id, 'resourceable_type' => $serviceB->getMorphClass(), 'is_preview' => false]);
    $serviceB->isConfigurationChanged(save: true);

    expect($serviceA->fresh()->config_hash)->toBe($serviceB->fresh()->config_hash);
});
