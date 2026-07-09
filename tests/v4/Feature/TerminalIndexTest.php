<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the terminal index Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('terminal'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Terminal/Index')
        ->has('servers', 0)
        ->missing('containers')
        ->has('terminalConfig')
        ->has('connectUrl')
    );
});

it('forbids terminal access for a non-admin member', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'member']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('terminal'));

    $response->assertForbidden();
});

it('rejects connect requests with no server or container selected', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson(route('terminal.connect'), ['selected_uuid' => 'default']);

    $response->assertStatus(422);
    $response->assertJson(['error' => 'Please select a server or a container.']);
});

it('rejects connect requests for a server the team does not own', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson(route('terminal.connect'), ['selected_uuid' => 'does-not-exist']);

    $response->assertStatus(404);
    $response->assertJson(['error' => 'Server not found.']);
});
