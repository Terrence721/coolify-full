<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the storage index Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $storage = S3Storage::create([
        'name' => 'Backups',
        'description' => 'Main backup bucket',
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'bucket' => 'my-bucket',
        'endpoint' => 'https://s3.us-east-1.amazonaws.com',
        'team_id' => $team->id,
        'is_usable' => true,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('storage.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Storage/Index')
        ->has('storages', 1)
        ->where('storages.0.name', 'Backups')
        ->where('storages.0.isUsable', true)
        ->where('canCreate', true)
    );
});

it('only lists storages owned by the current team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    S3Storage::create([
        'name' => 'Other Team Storage',
        'region' => 'us-east-1',
        'key' => 'k',
        'secret' => 's',
        'bucket' => 'b',
        'endpoint' => 'https://s3.us-east-1.amazonaws.com',
        'team_id' => $otherTeam->id,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('storage.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Storage/Index')
        ->has('storages', 0)
    );
});

it('forbids a non-admin from creating storage', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'member']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('storage.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Storage/Index')
        ->where('canCreate', false)
    );

    $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('storage.store'), [
            'name' => 'New Storage',
            'region' => 'us-east-1',
            'key' => 'k',
            'secret' => 's',
            'bucket' => 'b',
        ])
        ->assertForbidden();
});

it('rejects an unsafe endpoint without touching the network', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('storage.store'), [
            'name' => 'New Storage',
            'region' => 'us-east-1',
            'key' => 'k',
            'secret' => 's',
            'bucket' => 'b',
            'endpoint' => 'https://localhost/evil',
        ]);

    $response->assertSessionHasErrors(['endpoint']);
    expect(S3Storage::where('name', 'New Storage')->exists())->toBeFalse();
});

it('rejects a request missing required fields', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('storage.store'), ['name' => 'New Storage']);

    $response->assertSessionHasErrors(['region', 'key', 'secret', 'bucket']);
});
