<?php

declare(strict_types=1);

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

function sourcesActingAs(Team $team, string $role = 'admin'): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role]);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);

    return $user;
}

it('renders the sources page with the team github apps', function () {
    $team = Team::factory()->create();
    sourcesActingAs($team);
    GithubApp::create([
        'team_id' => $team->id,
        'name' => 'my-github-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'organization' => 'my-org',
    ]);

    $response = $this->get(route('source.all'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Sources/Index')
        ->has('sources', 1)
        ->where('sources.0.name', 'my-github-app')
        ->where('sources.0.organization', 'my-org')
        ->where('sources.0.configured', false)
        ->where('canCreate', true)
        ->has('storeUrl')
        ->has('defaultName')
    );
});

it('lists a system-wide github app from another team', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    sourcesActingAs($team);
    GithubApp::create([
        'team_id' => $otherTeam->id,
        'name' => 'shared-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_system_wide' => true,
    ]);

    $response = $this->get(route('source.all'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->has('sources', 1));
});

it('marks sources with an app_id as configured', function () {
    $team = Team::factory()->create();
    sourcesActingAs($team);
    GithubApp::create([
        'team_id' => $team->id,
        'name' => 'registered-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 123,
    ]);

    $response = $this->get(route('source.all'));

    $response->assertInertia(fn (Assert $page) => $page->where('sources.0.configured', true));
});

it('creates a github app and redirects to its configuration page', function () {
    $team = Team::factory()->create();
    sourcesActingAs($team);

    $response = $this->post(route('source.github.store'), [
        'name' => 'new-app',
        'organization' => 'my-org',
        'apiUrl' => 'https://api.github.com',
        'htmlUrl' => 'https://github.com',
        'customUser' => 'git',
        'customPort' => 22,
        'isSystemWide' => false,
    ]);

    $githubApp = GithubApp::where('name', 'new-app')->firstOrFail();
    expect($githubApp->team_id)->toBe($team->id);
    expect($githubApp->organization)->toBe('my-org');
    $response->assertRedirect(route('source.github.show', ['github_app_uuid' => $githubApp->uuid]));
});

it('rejects an unsafe api url', function () {
    $team = Team::factory()->create();
    sourcesActingAs($team);

    $response = $this->post(route('source.github.store'), [
        'name' => 'new-app',
        'apiUrl' => 'https://169.254.169.254/latest/meta-data',
        'htmlUrl' => 'https://github.com',
        'customUser' => 'git',
        'customPort' => 22,
        'isSystemWide' => false,
    ]);

    $response->assertSessionHasErrors('apiUrl');
    expect(GithubApp::where('name', 'new-app')->exists())->toBeFalse();
});
