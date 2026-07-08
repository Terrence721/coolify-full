<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the shared variables server index with the team\'s servers', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('shared-variables.server.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('SharedVariables/Server/Index')
        ->has('servers', 1)
        ->where('servers.0.name', $server->name)
        ->where('servers.0.href', route('shared-variables.server.show', ['server_uuid' => $server->uuid]))
    );
});
