<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

it('does not persist name/description changes when the urls step fails', function () {
    // Regression test: update_by_uuid() calls $service->save() with the new name/
    // description/docker_compose_raw/etc fields BEFORE validating `urls` - if
    // applyServiceUrls() then returns an error (invalid container name) or a domain
    // conflict, the handler returns a 422/409 with no compensating write, but the field
    // changes already committed. The client sees "nothing happened," but the service was
    // actually renamed. create_service() calls $service->delete() on the same failure for
    // a brand-new service; update_by_uuid() has no equivalent revert for an existing one.
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $service = Service::factory()->create([
        'name' => 'original-name',
        'environment_id' => $environment->id,
        'destination_type' => StandaloneDocker::class,
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/services/{$service->uuid}", [
        'name' => 'renamed',
        'urls' => [
            ['name' => 'no-such-container', 'url' => 'https://example.com'],
        ],
    ]);

    $response->assertStatus(422);
    expect($service->fresh()->name)->toBe('original-name');
});
