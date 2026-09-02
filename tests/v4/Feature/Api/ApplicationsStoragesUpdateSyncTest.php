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
use Tests\Support\InteractsWithApiV1;

require_once __DIR__.'/../../../Support/Fakes/model_remote_process_overrides.php';

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
    RemoteProcessFake::reset();
});

function updateSyncMakeApplication(Team $team): Application
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

// LocalFileVolume::booted() only dispatches ServerStorageSaveJob on `created`, not `updated` -
// PATCHing new content used to persist to the DB and return 200 without ever rewriting the
// actual file on the remote server, unlike both create() and the web UI's updateStorageFile().

it('writes updated file content to the server on update', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = updateSyncMakeApplication($team);
    $file = LocalFileVolume::create([
        'fs_path' => '/data/file.txt', 'mount_path' => '/file.txt', 'is_directory' => false,
        'resource_id' => $application->id, 'resource_type' => $application->getMorphClass(),
    ]);
    $token = $this->apiToken($user, $team, ['write']);
    RemoteProcessFake::$output = 'NOK';

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}/storages", [
        'id' => $file->id,
        'type' => 'file',
        'content' => 'new content',
    ]);

    $response->assertOk();
    expect(RemoteProcessFake::$instantRemoteProcessCalls)->not->toBeEmpty();
});

it('reverts the DB content when the server write fails', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $application = updateSyncMakeApplication($team);
    $file = LocalFileVolume::create([
        'fs_path' => '/data/file.txt', 'mount_path' => '/file.txt', 'is_directory' => false,
        'content' => 'original content',
        'resource_id' => $application->id, 'resource_type' => $application->getMorphClass(),
    ]);
    $token = $this->apiToken($user, $team, ['write']);
    RemoteProcessFake::$instantRemoteProcessException = new Exception('ssh connection failed');

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/applications/{$application->uuid}/storages", [
        'id' => $file->id,
        'type' => 'file',
        'content' => 'hijacked content',
    ]);

    $response->assertStatus(500);
    // Not $file->refresh(): see ApplicationsStoragesTest.php's "updates a file storage by id"
    // for why — LocalFileVolume's morphTo('resource') is cached under a key that doesn't match
    // its method name (service(), not resource()), so refresh()'s relation-reload breaks.
    expect(LocalFileVolume::find($file->id)->content)->toBe('original content');
});
