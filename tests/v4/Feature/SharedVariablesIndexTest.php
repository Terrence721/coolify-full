<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the shared variables Inertia page with the four scope links', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('shared-variables.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('SharedVariables/Index')
        ->has('links', 4)
        ->where('links.0.href', route('shared-variables.team.index'))
    );
});
