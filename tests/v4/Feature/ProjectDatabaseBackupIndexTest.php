<?php

declare(strict_types=1);

use App\Actions\Database\StopDatabase;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;
use Lorisleiva\Actions\Decorators\JobDecorator;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

// Server::factory()'s created hook auto-provisions a "coolify" StandaloneDocker
// destination; Project::booted()'s created hook auto-creates a "production" Environment.
// A fresh server's settings default to not-reachable/not-usable, so isFunctional() is
// false unless explicitly flipped on -- useful for safely exercising the "server not
// functional" branches without touching real SSH.
function makeTestPostgres(Team $team, Server $server): StandalonePostgresql
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->destinations()->first();

    return StandalonePostgresql::create([
        'name' => 'test-postgres',
        'postgres_password' => 'secret',
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'environment_id' => $environment->id,
        'status' => 'running',
    ]);
}

function databaseRouteParams(StandalonePostgresql $database): array
{
    $environment = $database->environment;
    $project = $environment->project;

    return [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'database_uuid' => $database->uuid,
    ];
}

it('renders the database backups page with scheduled backups', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $database = makeTestPostgres($team, $server);
    $backup = $database->scheduledBackups()->create([
        'frequency' => '@daily',
        'save_s3' => false,
        'team_id' => $team->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.database.backup.index', databaseRouteParams($database)));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Project/Database/Backup/Index')
        ->where('database.uuid', $database->uuid)
        ->has('scheduledBackups', 1)
        ->where('scheduledBackups.0.id', $backup->id)
        ->where('canUpdate', true)
    );
});

it('redirects to configuration for an engine that does not support backups', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->destinations()->first();
    $redis = StandaloneRedis::create([
        'name' => 'test-redis',
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'environment_id' => $environment->id,
        'status' => 'running',
    ]);
    $parameters = ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'database_uuid' => $redis->uuid];

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.database.backup.index', $parameters));

    $response->assertRedirect(route('project.database.configuration', $parameters));
});

it('redirects to the dashboard for a database owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $database = makeTestPostgres($otherTeam, $otherServer);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('project.database.backup.index', databaseRouteParams($database)));

    $response->assertRedirect(route('dashboard'));
});

it('creates a scheduled backup', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $database = makeTestPostgres($team, $server);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.database.backup.store', databaseRouteParams($database)), [
            'frequency' => '@daily',
            'save_to_s3' => false,
        ]);

    $response->assertRedirect();
    expect($database->scheduledBackups()->where('frequency', '@daily')->exists())->toBeTrue();
});

it('rejects an invalid cron expression when creating a scheduled backup', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $database = makeTestPostgres($team, $server);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.database.backup.store', databaseRouteParams($database)), [
            'frequency' => 'not-a-valid-expression',
            'save_to_s3' => false,
        ]);

    $response->assertSessionHas('error', 'Invalid Cron / Human expression.');
    expect($database->scheduledBackups()->count())->toBe(0);
});

it('rejects saving to s3 without a valid s3 storage', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $database = makeTestPostgres($team, $server);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.database.backup.store', databaseRouteParams($database)), [
            'frequency' => '@daily',
            'save_to_s3' => true,
            's3_storage_id' => null,
        ]);

    $response->assertSessionHas('error', 'Please select a valid S3 storage to enable S3 backups.');
    expect($database->scheduledBackups()->count())->toBe(0);
});

it('creates a scheduled backup with a valid s3 storage', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $database = makeTestPostgres($team, $server);
    $s3 = S3Storage::create([
        'name' => 'test-s3',
        'team_id' => $team->id,
        'is_usable' => true,
        'key' => 'key',
        'secret' => 'secret',
        'region' => 'us-east-1',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.database.backup.store', databaseRouteParams($database)), [
            'frequency' => '@daily',
            'save_to_s3' => true,
            's3_storage_id' => $s3->id,
        ]);

    $response->assertRedirect();
    $backup = $database->scheduledBackups()->where('frequency', '@daily')->first();
    expect($backup)->not->toBeNull();
    expect((bool) $backup->save_s3)->toBeTrue();
    expect($backup->s3_storage_id)->toBe($s3->id);
});

it('returns an error from check-status when the server is not functional', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $database = makeTestPostgres($team, $server);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.database.check-status', databaseRouteParams($database)));

    $response->assertSessionHas('error', 'Server is not functional.');
});

it('surfaces the not-functional message from start instead of crashing on a string activity', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $database = makeTestPostgres($team, $server);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.database.start', databaseRouteParams($database)));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Server is not functional');
});

it('surfaces the not-functional message from restart instead of crashing on a string activity', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $database = makeTestPostgres($team, $server);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.database.restart', databaseRouteParams($database)));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Server is not functional');
});

it('dispatches StopDatabase without touching SSH directly', function () {
    Bus::fake();

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $database = makeTestPostgres($team, $server);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('project.database.stop', databaseRouteParams($database)));

    $response->assertRedirect();
    $response->assertSessionHas('info', 'Gracefully stopping database.');
    Bus::assertDispatched(function (JobDecorator $job) {
        return $job->decorates(StopDatabase::class);
    });
});
