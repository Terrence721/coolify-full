<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// InstanceSettings::get() reads a singleton row hardcoded to id 0, seeded at install time in
// real deployments (see NotificationsEmailTest for the same gotcha) — some shared request-path
// code (exception reporting / host trust caching) touches this even for otherwise-unrelated pages.
beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the tags index without a tag selected', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    Tag::create(['name' => 'production', 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('tags.show'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Tags/Show')
        ->has('tags', 1)
        ->where('tags.0.name', 'production')
        ->where('tag', null)
    );
});

it('renders tag details, applications, and deployments when a tag is selected', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $tag = Tag::create(['name' => 'production', 'team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('tags.show', ['tagName' => 'production']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Tags/Show')
        ->where('tag.name', 'production')
        ->where('deploymentsPerTagPerServer', [])
    );
});
