<?php

declare(strict_types=1);

use App\Jobs\VolumeCloneJob;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;

// VolumeCloneJob's constructor properties are protected - read via reflection since there's no
// public accessor and this is the only place in the suite that needs to inspect a dispatched
// job's source/target volume names.
function readVolumeCloneJobProperty(VolumeCloneJob $job, string $name): string
{
    $property = new ReflectionProperty($job, $name);
    $property->setAccessible(true);

    return $property->getValue($job);
}

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

// Server::boot()'s static::created hook auto-creates a default StandaloneDocker (name/network
// both "coolify") for every server, so a fresh usable server already has one destination.
// Project::booted()'s static::created hook auto-creates a "production" Environment.
function makeCloneTestServer(int $teamId): Server
{
    $server = Server::factory()->create(['team_id' => $teamId]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);

    return $server;
}

it('renders the clone Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    makeCloneTestServer($team->id);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.clone-me', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/CloneMe')
        ->where('project.uuid', $project->uuid)
        ->has('destinations', 1)
        ->where('destinations.0.destinationName', 'coolify')
    );
});

it('clones an environment into a new project', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $server = makeCloneTestServer($team->id);
    $destination = $server->destinations()->first();
    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.clone-me.store', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]), [
            'type' => 'project',
            'name' => 'cloned-project',
            'destination_id' => $destination->id,
            'clone_volume_data' => false,
        ]);

    $newProject = Project::where('name', 'cloned-project')->first();
    expect($newProject)->not->toBeNull();
    $newEnvironment = $newProject->environments()->where('name', 'production')->first();
    expect($newEnvironment)->not->toBeNull();
    expect($newEnvironment->applications()->count())->toBe(1);
    $response->assertRedirect(route('project.resource.index', [
        'project_uuid' => $newProject->uuid,
        'environment_uuid' => $newEnvironment->uuid,
    ]));
});

it('clones an environment into a new environment within the same project', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $server = makeCloneTestServer($team->id);
    $destination = $server->destinations()->first();
    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.clone-me.store', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]), [
            'type' => 'environment',
            'name' => 'staging',
            'destination_id' => $destination->id,
            'clone_volume_data' => false,
        ]);

    $newEnvironment = $project->environments()->where('name', 'staging')->first();
    expect($newEnvironment)->not->toBeNull();
    expect($newEnvironment->applications()->count())->toBe(1);
    $response->assertRedirect(route('project.resource.index', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $newEnvironment->uuid,
    ]));
});

it('rejects cloning into a project name that already exists', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $server = makeCloneTestServer($team->id);
    $destination = $server->destinations()->first();
    Project::factory()->create(['team_id' => $team->id, 'name' => 'taken-name']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.clone-me.store', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]), [
            'type' => 'project',
            'name' => 'taken-name',
            'destination_id' => $destination->id,
            'clone_volume_data' => false,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Project with the same name already exists.');
});

// A real, parseable docker-compose service with an application and a database, each with a
// named volume, so Service::parse() populates real ServiceApplication/ServiceDatabase rows
// with real persistentStorages the same way the actual create-service flow does - exercising
// the full real parser, not a hand-built fixture standing in for it. Deliberately no
// bind-mounted file volume: serviceParser() synchronously dispatches ServerFilesFromServerJob
// for those (real behaviour, not specific to this fix), which needs a real reachable server -
// out of scope for what this fixture needs to prove.
function makeCloneTestComposeService(int $environmentId, int $destinationId, int $serverId): Service
{
    $compose = <<<'YAML'
services:
  app:
    image: nginx:alpine
    volumes:
      - app-data:/usr/share/nginx/html
  db:
    image: postgres:15
    volumes:
      - db-data:/var/lib/postgresql/data
volumes:
  app-data:
  db-data:
YAML;

    $service = Service::create([
        'name' => 'clone-source-service',
        'environment_id' => $environmentId,
        'destination_id' => $destinationId,
        'destination_type' => StandaloneDocker::class,
        'server_id' => $serverId,
        'docker_compose_raw' => $compose,
    ]);
    $service->parse();

    return $service->fresh();
}

it('clones a service, its applications/databases, and their persistent storages with new names', function () {
    // serviceParser() itself dispatches ServerFilesFromServerJob for every volume it processes
    // (real behaviour, unrelated to this fix) - fake the bus so it doesn't run synchronously
    // against a real, unreachable server.
    Bus::fake();

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $server = makeCloneTestServer($team->id);
    $destination = $server->destinations()->first();

    $service = makeCloneTestComposeService($environment->id, $destination->id, $server->id);
    $sourceApp = $service->applications()->where('name', 'app')->firstOrFail();
    $sourceDb = $service->databases()->where('name', 'db')->firstOrFail();
    $sourceAppVolume = $sourceApp->persistentStorages()->firstOrFail();
    $sourceDbVolume = $sourceDb->persistentStorages()->firstOrFail();

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.clone-me.store', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]), [
            'type' => 'environment',
            'name' => 'staging',
            'destination_id' => $destination->id,
            'clone_volume_data' => false,
        ]);

    $newEnvironment = $project->environments()->where('name', 'staging')->firstOrFail();
    $newService = $newEnvironment->services()->where('name', 'clone-source-service')->firstOrFail();

    // The bug this locks in: parse() used to run last, so applications()/databases() were
    // always empty when these loops ran - nothing below was ever reachable at all.
    expect($newService->applications()->count())->toBe(1);
    expect($newService->databases()->count())->toBe(1);

    $newApp = $newService->applications()->where('name', 'app')->firstOrFail();
    $newDb = $newService->databases()->where('name', 'db')->firstOrFail();

    // persistentStorages are created by parse() itself (keyed off $newService's own fresh
    // uuid), so they must already be correctly, uniquely named - not the source's name, and
    // not the source's name with a no-op self-replace.
    $newAppVolume = $newApp->persistentStorages()->firstOrFail();
    expect($newAppVolume->name)->not->toBe($sourceAppVolume->name);
    expect($newAppVolume->name)->toContain($newService->uuid);
    expect($newAppVolume->mount_path)->toBe($sourceAppVolume->mount_path);

    $newDbVolume = $newDb->persistentStorages()->firstOrFail();
    expect($newDbVolume->name)->not->toBe($sourceDbVolume->name);
    expect($newDbVolume->name)->toContain($newService->uuid);

    $response->assertRedirect(route('project.resource.index', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $newEnvironment->uuid,
    ]));
});

it('dispatches a VolumeCloneJob from the source volume to the new volume for each app/database when clone_volume_data is true', function () {
    Bus::fake();

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $server = makeCloneTestServer($team->id);
    $destination = $server->destinations()->first();

    $service = makeCloneTestComposeService($environment->id, $destination->id, $server->id);
    $sourceAppVolumeName = $service->applications()->where('name', 'app')->firstOrFail()->persistentStorages()->firstOrFail()->name;
    $sourceDbVolumeName = $service->databases()->where('name', 'db')->firstOrFail()->persistentStorages()->firstOrFail()->name;

    $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.clone-me.store', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]), [
            'type' => 'environment',
            'name' => 'staging-with-data',
            'destination_id' => $destination->id,
            'clone_volume_data' => true,
        ]);

    $newEnvironment = $project->environments()->where('name', 'staging-with-data')->firstOrFail();
    $newService = $newEnvironment->services()->where('name', 'clone-source-service')->firstOrFail();
    $newAppVolumeName = $newService->applications()->where('name', 'app')->firstOrFail()->persistentStorages()->firstOrFail()->name;
    $newDbVolumeName = $newService->databases()->where('name', 'db')->firstOrFail()->persistentStorages()->firstOrFail()->name;

    Bus::assertDispatched(VolumeCloneJob::class, function (VolumeCloneJob $job) use ($sourceAppVolumeName, $newAppVolumeName) {
        return readVolumeCloneJobProperty($job, 'sourceVolume') === $sourceAppVolumeName
            && readVolumeCloneJobProperty($job, 'targetVolume') === $newAppVolumeName;
    });
    Bus::assertDispatched(VolumeCloneJob::class, function (VolumeCloneJob $job) use ($sourceDbVolumeName, $newDbVolumeName) {
        return readVolumeCloneJobProperty($job, 'sourceVolume') === $sourceDbVolumeName
            && readVolumeCloneJobProperty($job, 'targetVolume') === $newDbVolumeName;
    });
});

it('does not dispatch a VolumeCloneJob when clone_volume_data is false', function () {
    Bus::fake();

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $server = makeCloneTestServer($team->id);
    $destination = $server->destinations()->first();
    makeCloneTestComposeService($environment->id, $destination->id, $server->id);

    $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.clone-me.store', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]), [
            'type' => 'environment',
            'name' => 'staging-no-data',
            'destination_id' => $destination->id,
            'clone_volume_data' => false,
        ]);

    Bus::assertNotDispatched(VolumeCloneJob::class);
});

it('rejects a destination_id belonging to another team instead of cloning databases and services to it', function () {
    // Regression test: the controller already computed $selectedDestination by searching only
    // currentTeam()'s own servers' destinations (so a cross-team id would never be found there),
    // but the database and service clone blocks bypassed it entirely and wrote the raw, unchecked
    // $validated['destination_id'] straight into the replicated row instead. StandaloneDocker/
    // SwarmDocker's server() relation and Service::destination() are both unscoped - once
    // deployed (StartDatabase::handle() resolves destination.server and deploys there if
    // functional), the clone would genuinely run real containers on another team's server over
    // that server's own SSH credentials. The application clone path was already correct
    // (clone_application() uses $selectedDestination and additionally re-checks team_id itself)
    // - only the database/service paths had the gap.
    Bus::fake();

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $server = makeCloneTestServer($team->id);
    $destination = $server->destinations()->first();

    $otherTeam = Team::factory()->create();
    $otherServer = makeCloneTestServer($otherTeam->id);
    $otherDestination = $otherServer->destinations()->first();

    StandalonePostgresql::create([
        'name' => 'clone-source-db',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'postgres_password' => 'secret',
    ]);
    makeCloneTestComposeService($environment->id, $destination->id, $server->id);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.clone-me.store', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]), [
            'type' => 'environment',
            'name' => 'malicious-clone',
            'destination_id' => $otherDestination->id,
            'clone_volume_data' => false,
        ]);

    expect($project->environments()->where('name', 'malicious-clone')->exists())->toBeFalse();
    $response->assertSessionHas('error');
});

it('rejects submission without a destination selected', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.clone-me.store', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]), [
            'type' => 'project',
            'name' => 'no-destination',
            'destination_id' => null,
            'clone_volume_data' => false,
        ]);

    $response->assertSessionHasErrors('destination_id');
});
