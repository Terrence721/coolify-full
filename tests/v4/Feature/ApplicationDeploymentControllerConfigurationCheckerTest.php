<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Visus\Cuid2\Cuid2;

// Regression/gap coverage for docs/smoketest.md's deferred "configuration changed banner +
// redacted diff" item (issue #22): a member (non-admin) must see masked environment-variable
// values in the configuration diff, while an owner/admin sees the real ones. Confirmed live in a
// real browser for the owner side (issue #22 smoke test, 2026-07-24) - the owner correctly saw
// the real new value in the diff modal. The member side can't be exercised live in this dev
// environment (no in-app team switcher, see AppLayout.jsx), so it's covered here directly against
// ApplicationDeploymentController::configurationCheckerProps() instead.

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function cdcMakeApplicationWithBaseline(Team $team): Application
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();

    $application = Application::factory()->create([
        'name' => 'my-app',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockerfile',
        'dockerfile' => "FROM alpine\n",
    ]);

    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => (string) new Cuid2,
        'status' => 'finished',
        'pull_request_id' => 0,
    ]);
    $application->markDeploymentConfigurationApplied($deployment);

    // Change the deployable config after the baseline was recorded, so the diff actually has
    // something to show - a real env var, added post-baseline, same as the live smoke test.
    $application->environment_variables()->create([
        'key' => 'FEATURE_FLAG_NEW_CHECKOUT',
        'value' => 'super-secret-flag-value-123',
        'is_multiline' => false,
        'is_literal' => false,
        'is_buildtime' => false,
        'is_runtime' => true,
    ]);

    return $application->fresh();
}

function cdcParams(Application $application): array
{
    return [
        'project_uuid' => $application->environment->project->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
    ];
}

it('shows the real environment variable value to an admin/owner', function () {
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $team->members()->attach($owner, ['role' => 'owner']);
    test()->actingAs($owner)->withSession(['currentTeam' => $team]);

    $application = cdcMakeApplicationWithBaseline($team);

    $response = test()->get(route('project.application.deployment.index', cdcParams($application)));

    $response->assertOk();
    $response->assertInertia(function (Assert $page) {
        $page->where('configurationChecker.isConfigurationChanged', true);
        $changes = collect($page->toArray()['props']['configurationChecker']['diff']['changes']);
        $envChange = $changes->firstWhere('label', 'FEATURE_FLAG_NEW_CHECKOUT');
        expect($envChange)->not->toBeNull()
            ->and($envChange['new_display_value'])->toBe('super-secret-flag-value-123')
            ->and($envChange['expandable'])->not->toBeFalse();
    });
});

it('masks the environment variable value for a plain member', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => 'member']);
    test()->actingAs($member)->withSession(['currentTeam' => $team]);

    $application = cdcMakeApplicationWithBaseline($team);

    $response = test()->get(route('project.application.deployment.index', cdcParams($application)));

    $response->assertOk();
    $response->assertInertia(function (Assert $page) {
        $page->where('configurationChecker.isConfigurationChanged', true);
        $changes = collect($page->toArray()['props']['configurationChecker']['diff']['changes']);
        $envChange = $changes->firstWhere('label', 'FEATURE_FLAG_NEW_CHECKOUT');
        expect($envChange)->not->toBeNull()
            ->and($envChange['new_display_value'])->toBe('••••••••')
            ->and($envChange['new_full_value'])->toBeNull()
            ->and($envChange['expandable'])->toBeFalse();
    });
});
