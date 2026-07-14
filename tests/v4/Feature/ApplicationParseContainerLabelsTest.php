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

function parseContainerLabelsMakeApplication(array $attrs = []): Application
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

it('replaces commas with newlines in custom labels not yet base64-encoded', function () {
    $application = parseContainerLabelsMakeApplication([
        'custom_labels' => 'traefik.enable=true,traefik.http.routers.foo.rule=Host(`example.com`)',
    ]);

    $result = $application->parseContainerLabels();

    expect($result)->toBe("traefik.enable=true\ntraefik.http.routers.foo.rule=Host(`example.com`)");
    expect(base64_decode($application->fresh()->custom_labels))->toBe($result);
});

it('regenerates labels without crashing when the decoded value is not valid UTF-8', function () {
    $application = parseContainerLabelsMakeApplication([
        'custom_labels' => base64_encode("\xB1\x31"),
    ]);

    $result = $application->parseContainerLabels();

    expect($result)->toBeString();
    expect(base64_decode($application->fresh()->custom_labels))->toBe($result);
});
