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

function pruneStaleDomainsMakeApplication(array $attrs = []): Application
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

it('nulls docker_compose_domains once every mapped service has been removed from the compose file', function () {
    $application = pruneStaleDomainsMakeApplication([
        'docker_compose_domains' => json_encode(['web' => ['domain' => 'example.com']]),
    ]);

    // "web" no longer exists in the parsed compose file - only "api" remains.
    $application->pruneStaleDockerComposeDomains(['services' => ['api' => []]]);

    expect($application->fresh()->docker_compose_domains)->toBeNull();
});

it('keeps docker_compose_domains entries for services still present in the compose file', function () {
    $application = pruneStaleDomainsMakeApplication([
        'docker_compose_domains' => json_encode(['web' => ['domain' => 'example.com'], 'api' => ['domain' => 'api.example.com']]),
    ]);

    // "web" was removed, "api" is still a real service.
    $application->pruneStaleDockerComposeDomains(['services' => ['api' => []]]);

    $decoded = json_decode($application->fresh()->docker_compose_domains, true);
    expect($decoded)->toBe(['api' => ['domain' => 'api.example.com']]);
});
