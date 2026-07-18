<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('marks a server\'s services\' child applications exited when the server is unreachable', function () {
    $team = Team::factory()->create();
    // ip '1.2.3.4' makes skipServer() return true, so validateConnection()
    // reports uptime=false without needing a real SSH connection.
    $server = Server::factory()->create(['team_id' => $team->id, 'ip' => '1.2.3.4']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->destinations()->first();

    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $serviceApplication = ServiceApplication::create([
        'name' => 'app-child',
        'service_id' => $service->id,
        'status' => 'running',
    ]);

    $server->status();

    expect($serviceApplication->fresh()->status)->toBe('exited');
});
