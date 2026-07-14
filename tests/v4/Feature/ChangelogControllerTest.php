<?php

declare(strict_types=1);

use App\Jobs\PullChangelog;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Models\UserChangelogRead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function changelogActingAs(): User
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    test()->actingAs($user)->withSession(['currentTeam' => $team]);

    return $user;
}

it('returns the current user\'s changelog entries, unread count, and version', function () {
    changelogActingAs();

    $response = $this->getJson(route('changelog.entries'));

    $response->assertOk();
    $response->assertJsonStructure(['entries', 'unreadCount', 'currentVersion']);
    expect($response->json('currentVersion'))->toStartWith('v');
});

it('marks a single changelog entry as read', function () {
    $user = changelogActingAs();

    $response = $this->postJson(route('changelog.mark-read'), ['identifier' => 'v4.9.9']);

    $response->assertOk()->assertJson(['success' => true]);
    expect(UserChangelogRead::where('user_id', $user->id)->where('release_tag', 'v4.9.9')->exists())->toBeTrue();
});

it('rejects marking an entry as read without an identifier', function () {
    changelogActingAs();

    $this->postJson(route('changelog.mark-read'), [])->assertUnprocessable();
});

it('marks all changelog entries as read without error', function () {
    changelogActingAs();

    $this->postJson(route('changelog.mark-all-read'))
        ->assertOk()
        ->assertJson(['success' => true]);
});

it('rejects a manual changelog fetch outside of local dev', function () {
    changelogActingAs();

    $this->postJson(route('changelog.fetch'))->assertNotFound();
});

it('queues a changelog fetch in local dev', function () {
    Queue::fake();
    config(['app.env' => 'local']);
    changelogActingAs();

    $response = $this->postJson(route('changelog.fetch'));

    $response->assertOk()->assertJson(['success' => true]);
    Queue::assertPushed(PullChangelog::class);

    config(['app.env' => 'testing']);
});
