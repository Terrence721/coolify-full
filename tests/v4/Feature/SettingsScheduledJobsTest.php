<?php

declare(strict_types=1);

use App\Models\DockerCleanupExecution;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('forbids access for a non-instance-admin user', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('settings.scheduled-jobs'));

    $response->assertRedirect(route('dashboard'));
});

it('renders the settings scheduled jobs Inertia page with default filters', function () {
    $rootUser = User::forceCreate(User::factory()->raw(['id' => 0]));
    $rootTeam = $rootUser->teams()->first();
    $rootTeam->update(['show_boarding' => false]);

    $response = $this->actingAs($rootUser)
        ->withSession(['currentTeam' => $rootTeam])
        ->get(route('settings.scheduled-jobs'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Settings/ScheduledJobs')
        ->where('filterType', 'all')
        ->where('filterDate', 'last_24h')
        ->has('executions')
        ->has('managerRuns')
        ->has('skipLogs')
    );
});

it('includes a failed docker cleanup execution in the executions list', function () {
    $rootUser = User::forceCreate(User::factory()->raw(['id' => 0]));
    $rootTeam = $rootUser->teams()->first();
    $rootTeam->update(['show_boarding' => false]);
    $server = Server::factory()->create(['team_id' => $rootTeam->id, 'name' => 'prod-1']);
    DockerCleanupExecution::create([
        'server_id' => $server->id,
        'status' => 'failed',
        'message' => 'disk cleanup failed',
    ]);

    $response = $this->actingAs($rootUser)
        ->withSession(['currentTeam' => $rootTeam])
        ->get(route('settings.scheduled-jobs', ['filterType' => 'cleanup', 'filterDate' => 'all']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Settings/ScheduledJobs')
        ->has('executions', 1)
        ->where('executions.0.type', 'cleanup')
        ->where('executions.0.server_name', 'prod-1')
    );
});
