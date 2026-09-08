<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithApiV1;

uses(RefreshDatabase::class, InteractsWithApiV1::class);

beforeEach(function () {
    $this->apiEnable();
});

function apiEnvsMakeDatabase(Team $team, array $attrs = []): StandalonePostgresql
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();
    $destination = $server->standaloneDockers()->first();

    return StandalonePostgresql::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        ...$attrs,
    ]);
}

// envs() GET — Tier A

it('lists env vars for a database', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiEnvsMakeDatabase($team);
    $database->environment_variables()->create(['key' => 'REGULAR', 'value' => '1']);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/databases/{$database->uuid}/envs");

    $response->assertOk();
    $response->assertJsonFragment(['key' => 'REGULAR']);
});

it('returns 404 listing envs for another team\'s database', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiEnvsMakeDatabase($otherTeam);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/databases/{$database->uuid}/envs");

    $response->assertNotFound();
});

it('hides env values without the read:sensitive ability', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiEnvsMakeDatabase($team);
    $database->environment_variables()->create(['key' => 'SECRET', 'value' => 'shh']);
    $token = $this->apiToken($user, $team, ['read']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/databases/{$database->uuid}/envs");

    $response->assertOk();
    $response->assertJsonMissingPath('0.value');
});

it('shows env values with the read:sensitive ability', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiEnvsMakeDatabase($team);
    $database->environment_variables()->create(['key' => 'SECRET', 'value' => 'shh']);
    $token = $this->apiToken($user, $team, ['read', 'read:sensitive']);

    $response = $this->withHeaders($this->apiHeaders($token))->getJson("/api/v1/databases/{$database->uuid}/envs");

    $response->assertOk();
    $response->assertJsonFragment(['value' => 'shh']);
});

// update_env_by_uuid()

it('updates an env var by key', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiEnvsMakeDatabase($team);
    $env = $database->environment_variables()->create(['key' => 'FOO', 'value' => 'old']);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/databases/{$database->uuid}/envs", [
        'key' => 'FOO',
        'value' => 'new',
    ]);

    $response->assertCreated();
    expect($env->fresh()->value)->toBe('new');
});

it('returns 404 updating an env var that does not exist', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiEnvsMakeDatabase($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/databases/{$database->uuid}/envs", [
        'key' => 'NONEXISTENT',
        'value' => 'new',
    ]);

    $response->assertNotFound();
});

// create_env()

it('creates an env var', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiEnvsMakeDatabase($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/databases/{$database->uuid}/envs", [
        'key' => 'NEW_VAR',
        'value' => 'hello',
    ]);

    $response->assertCreated();
    expect($database->environment_variables()->where('key', 'NEW_VAR')->exists())->toBeTrue();
});

it('rejects creating an env var without a key', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiEnvsMakeDatabase($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/databases/{$database->uuid}/envs", [
        'value' => 'hello',
    ]);

    $response->assertStatus(422);
});

it('returns 409 creating an env var that already exists', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiEnvsMakeDatabase($team);
    $database->environment_variables()->create(['key' => 'DUPLICATE', 'value' => 'old']);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->postJson("/api/v1/databases/{$database->uuid}/envs", [
        'key' => 'DUPLICATE',
        'value' => 'new',
    ]);

    $response->assertStatus(409);
});

// create_bulk_envs()

it('bulk-creates env vars', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiEnvsMakeDatabase($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/databases/{$database->uuid}/envs/bulk", [
        'data' => [
            ['key' => 'BULK_ONE', 'value' => '1'],
            ['key' => 'BULK_TWO', 'value' => '2'],
        ],
    ]);

    $response->assertCreated();
    expect($database->environment_variables()->where('key', 'BULK_ONE')->exists())->toBeTrue();
    expect($database->environment_variables()->where('key', 'BULK_TWO')->exists())->toBeTrue();
});

it('ignores resourceable_id/resourceable_type supplied when bulk-updating an existing env var', function () {
    // Regression test: create_bulk_envs() passed the raw request item straight into
    // updateOrCreate(), and EnvironmentVariable::$fillable includes resourceable_id and
    // resourceable_type. On the create path, the relation re-asserts the correct foreign
    // attributes after fill() so that's safe - but on the update path (an env var with
    // that key already exists), updateOrCreate() only calls $instance->fill($values), with
    // no re-assertion, so a caller with write access to one database could hijack an
    // *existing* env var onto an arbitrary resource by including those keys in the bulk
    // payload. Sibling endpoints (create_env, update_env_by_uuid, and Applications'/
    // Services' own create_bulk_envs()) all build the attributes field-by-field and aren't
    // affected.
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiEnvsMakeDatabase($team);
    $otherDatabase = apiEnvsMakeDatabase($team);
    $database->environment_variables()->create(['key' => 'EXISTING', 'value' => 'original']);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/databases/{$database->uuid}/envs/bulk", [
        'data' => [
            [
                'key' => 'EXISTING',
                'value' => 'evil',
                'resourceable_id' => $otherDatabase->id,
                'resourceable_type' => get_class($otherDatabase),
            ],
        ],
    ]);

    $response->assertCreated();
    $env = $database->environment_variables()->where('key', 'EXISTING')->first();
    expect($env)->not->toBeNull();
    expect($env->value)->toBe('evil');
    expect($env->resourceable_id)->toBe($database->id);
    expect($env->resourceable_type)->toBe(get_class($database));
});

it('rejects bulk env creation with missing data', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiEnvsMakeDatabase($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->patchJson("/api/v1/databases/{$database->uuid}/envs/bulk", []);

    $response->assertStatus(400);
});

// delete_env_by_uuid()

it('deletes an env var', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiEnvsMakeDatabase($team);
    $env = $database->environment_variables()->create(['key' => 'FOO', 'value' => 'bar']);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/databases/{$database->uuid}/envs/{$env->uuid}");

    $response->assertOk();
    expect($database->environment_variables()->where('key', 'FOO')->exists())->toBeFalse();
});

it('returns 404 deleting an env var that does not exist', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $database = apiEnvsMakeDatabase($team);
    $token = $this->apiToken($user, $team, ['write']);

    $response = $this->withHeaders($this->apiHeaders($token))->deleteJson("/api/v1/databases/{$database->uuid}/envs/nonexistent-uuid");

    $response->assertNotFound();
});
