<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithApiV1;

// Regression coverage for a real credential leak: get_application_deployments()
// (GET /api/v1/deployments/applications/{uuid}) returned raw ApplicationDeploymentQueue rows with
// zero redaction, unlike its two siblings in the same controller (deployments(),
// deployment_by_uuid()) which both route through removeSensitiveData() to hide the logs column
// unless the token carries read:sensitive. All 3 routes share the identical api.ability:read
// gate, so there's no ability difference explaining the exemption - this endpoint just forgot to
// call the controller's own already-correct redaction helper. logs is real build/deploy console
// output (written by ExecuteRemoteCommand during deployment) and routinely contains printed env
// values, git URLs with embedded access tokens, and registry auth output - exactly why the other
// two endpoints already gate it.

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

function makeApiApplicationWithDeploymentLogs(string $secretLogs): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = $server->standaloneDockers()->first() ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);

    $application = Application::factory()->create([
        'team_id' => $team->id,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => (string) Str::uuid(),
        'server_id' => $server->id,
        'status' => 'finished',
        'logs' => $secretLogs,
    ]);

    return [$team, $user, $application];
}

it('never leaks deployment logs into the application-deployments-list API response', function () {
    [$team, $user, $application] = makeApiApplicationWithDeploymentLogs('SECRET-DEPLOY-LOG-CONTENTS');
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/deployments/applications/{$application->uuid}");

    $response->assertOk();
    expect($response->getContent())->not->toContain('SECRET-DEPLOY-LOG-CONTENTS');
});

it('still returns the deployment status/uuid once logs are redacted', function () {
    [$team, $user, $application] = makeApiApplicationWithDeploymentLogs('SECRET-DEPLOY-LOG-CONTENTS');
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/deployments/applications/{$application->uuid}");

    $response->assertOk();
    $response->assertJsonFragment(['status' => 'finished']);
    $response->assertJsonPath('count', 1);
});

it('exposes deployment logs only when the token carries read:sensitive', function () {
    [$team, $user, $application] = makeApiApplicationWithDeploymentLogs('SECRET-DEPLOY-LOG-CONTENTS');
    $token = $this->apiToken($user, $team, ['read', 'read:sensitive']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/deployments/applications/{$application->uuid}");

    $response->assertOk();
    $response->assertJsonFragment(['logs' => 'SECRET-DEPLOY-LOG-CONTENTS']);
});
