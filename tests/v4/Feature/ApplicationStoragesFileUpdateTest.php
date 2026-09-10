<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\LocalFileVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fakes\RemoteProcessFake;

require_once __DIR__.'/../../Support/Fakes/model_remote_process_overrides.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    RemoteProcessFake::reset();
});

// Without this, $instantRemoteProcessException set by the revert-path test below leaks past
// this file into whichever test runs next in the same process (RemoteProcessFake's state is
// static) - confirmed live: it broke an unrelated DestinationShowTest deletion test in CI.
afterEach(function () {
    RemoteProcessFake::reset();
});

function webStorageFileUpdateMakeApplication(Team $team): Application
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
}

function webStorageFileUpdateParams(Application $application, LocalFileVolume $file): array
{
    return [
        'project_uuid' => $application->environment->project->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
        'file_id' => $file->id,
    ];
}

// Mirrors ApplicationsStoragesUpdateSyncTest.php (tests/v4/Feature/Api) - same underlying
// LocalFileVolume::saveStorageOnServer()/encrypted-content revert path, exercised here via the
// web UI's updateStorageFile() instead of the API's applyApiStorageUpdate(). Previously
// untested: see ManagesResourceStorages::updateStorageFile()'s own revert-on-failure logic.

it('writes updated file content to the server on update', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $this->actingAs($user)->withSession(['currentTeam' => $team]);

    $application = webStorageFileUpdateMakeApplication($team);
    $file = LocalFileVolume::create([
        'fs_path' => '/data/file.txt', 'mount_path' => '/file.txt', 'is_directory' => false,
        'resource_id' => $application->id, 'resource_type' => $application->getMorphClass(),
    ]);
    RemoteProcessFake::$output = 'NOK';

    $response = $this->patch(
        route('project.application.storages.file.update', webStorageFileUpdateParams($application, $file)),
        ['content' => 'new content']
    );

    $response->assertSessionHas('success', 'File updated.');
    expect(RemoteProcessFake::$instantRemoteProcessCalls)->not->toBeEmpty();
});

it('reverts the DB content when the server write fails, without corrupting the encrypted column', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $this->actingAs($user)->withSession(['currentTeam' => $team]);

    $application = webStorageFileUpdateMakeApplication($team);
    $file = LocalFileVolume::create([
        'fs_path' => '/data/file.txt', 'mount_path' => '/file.txt', 'is_directory' => false,
        'content' => 'original content',
        'resource_id' => $application->id, 'resource_type' => $application->getMorphClass(),
    ]);
    RemoteProcessFake::$instantRemoteProcessException = new Exception('ssh connection failed');

    $response = $this->patch(
        route('project.application.storages.file.update', webStorageFileUpdateParams($application, $file)),
        ['content' => 'hijacked content']
    );

    $response->assertSessionHas('error', 'ssh connection failed');
    // Not $file->refresh(): LocalFileVolume's morphTo('resource') is cached under a key that
    // doesn't match its method name (service(), not resource()) - refresh()'s relation-reload
    // breaks on it. A fresh find() sidesteps that entirely.
    expect(LocalFileVolume::find($file->id)->content)->toBe('original content');
});
