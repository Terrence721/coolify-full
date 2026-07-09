<?php

declare(strict_types=1);

use App\Models\CloudInitScript;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the security cloud-init scripts Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('security.cloud-init-scripts'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Security/CloudInitScripts')
        ->where('canCreate', true)
        ->has('scripts', 0)
        ->where('storeUrl', route('security.cloud-init-scripts.store'))
    );
});

it('forbids cloud-init scripts access for a non-admin member', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'member']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('security.cloud-init-scripts'));

    $response->assertForbidden();
});

it('creates a new cloud-init script', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('security.cloud-init-scripts.store'), [
            'name' => 'My Script',
            'script' => "#!/bin/bash\necho hello",
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect(CloudInitScript::where('team_id', $team->id)->where('name', 'My Script')->exists())->toBeTrue();
});

it('rejects a cloud-init script with invalid yaml', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('security.cloud-init-scripts.store'), [
            'name' => 'Bad Script',
            'script' => "#cloud-config\nfoo: [unterminated",
        ]);

    $response->assertSessionHasErrors('script');
    expect(CloudInitScript::where('team_id', $team->id)->where('name', 'Bad Script')->exists())->toBeFalse();
});

it('updates an existing cloud-init script', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $script = CloudInitScript::create([
        'team_id' => $team->id,
        'name' => 'Original',
        'script' => "#!/bin/bash\necho original",
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->put(route('security.cloud-init-scripts.update', ['id' => $script->id]), [
            'name' => 'Renamed',
            'script' => "#!/bin/bash\necho renamed",
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect($script->fresh()->name)->toBe('Renamed');
});

it('deletes a cloud-init script', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $script = CloudInitScript::create([
        'team_id' => $team->id,
        'name' => 'Deletable',
        'script' => "#!/bin/bash\necho bye",
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->delete(route('security.cloud-init-scripts.destroy', ['id' => $script->id]));

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect(CloudInitScript::find($script->id))->toBeNull();
});
