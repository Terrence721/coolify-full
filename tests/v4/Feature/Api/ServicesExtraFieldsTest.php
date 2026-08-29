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

// create_service()'s docker_compose_raw branch has its own validateExtraFields() call
// (converted here too), but its allowedFields is a subset of the outer allowedFields
// checked earlier in the same method, so the outer call already rejects any field the
// inner one would — the "rejects an is_public field as not allowed" case in
// ServicesCreateTest.php covers this method's extraFields behavior end-to-end.

it('rejects an unexpected field on update', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'destination_type' => StandaloneDocker::class,
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/services/{$service->uuid}", [
        'name' => 'renamed',
        'unexpected_field' => 'nope',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('unexpected_field');
});

it('still updates a service with only allowed fields', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'destination_type' => StandaloneDocker::class,
    ]);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/services/{$service->uuid}", [
        'name' => 'renamed',
    ]);

    $response->assertOk();
});
