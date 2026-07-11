<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function makeGithubApp(int $teamId, array $overrides = []): GithubApp
{
    return GithubApp::create(array_merge([
        'team_id' => $teamId,
        'name' => 'my-github-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
    ], $overrides));
}

it('renders the pre-registration state when app_id is not set', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $githubApp = makeGithubApp($team->id);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('source.github.show', ['github_app_uuid' => $githubApp->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Source/Github/Change')
        ->where('githubApp.appId', null)
        ->where('canCreate', true)
        ->has('manifestState')
    );
});

it('renders the tabbed configuration state once app_id and installation_id are set', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $githubApp = makeGithubApp($team->id, ['app_id' => 123, 'installation_id' => 456]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('source.github.permissions', ['github_app_uuid' => $githubApp->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Source/Github/Change')
        ->where('activeTab', 'permissions')
        ->where('githubApp.appId', 123)
    );
});

it('returns 404 for a github app owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $githubApp = makeGithubApp($otherTeam->id);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('source.github.show', ['github_app_uuid' => $githubApp->uuid]));

    $response->assertNotFound();
});

it('updates the github app configuration', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $githubApp = makeGithubApp($team->id, ['app_id' => 123, 'installation_id' => 456]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('source.github.update', ['github_app_uuid' => $githubApp->uuid]), [
            'name' => 'renamed-app',
            'organization' => null,
            'apiUrl' => 'https://api.github.com',
            'htmlUrl' => 'https://github.com',
            'customUser' => 'git',
            'customPort' => 22,
            'appId' => 123,
            'installationId' => 456,
            'clientId' => 'client-id',
            'clientSecret' => 'client-secret',
            'webhookSecret' => 'webhook-secret',
            'isSystemWide' => false,
            'privateKeyId' => null,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Github App updated.');
    expect($githubApp->fresh()->name)->toBe('renamed-app');
});

it('rejects an unsafe apiUrl on update', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $githubApp = makeGithubApp($team->id, ['app_id' => 123, 'installation_id' => 456]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('source.github.update', ['github_app_uuid' => $githubApp->uuid]), [
            'name' => 'renamed-app',
            'apiUrl' => 'http://127.0.0.1/internal',
            'htmlUrl' => 'https://github.com',
            'customUser' => 'git',
            'customPort' => 22,
            'isSystemWide' => false,
        ]);

    $response->assertSessionHasErrors(['apiUrl']);
});

it('rejects deleting a github app that is still used by an application', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $githubApp = makeGithubApp($team->id);
    Application::factory()->create([
        'source_id' => $githubApp->id,
        'source_type' => GithubApp::class,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('source.github.destroy', ['github_app_uuid' => $githubApp->uuid]));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'This source is being used by an application. Please delete all applications first.');
    expect(GithubApp::find($githubApp->id))->not->toBeNull();
});

it('deletes a github app with no applications', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $githubApp = makeGithubApp($team->id);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('source.github.destroy', ['github_app_uuid' => $githubApp->uuid]));

    $response->assertRedirect(route('source.all'));
    expect(GithubApp::find($githubApp->id))->toBeNull();
});
