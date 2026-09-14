<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Regression coverage for issue #66: no model in this codebase logged "who changed what" for
// team-visible resources. LogsTeamAudit (app/Traits/LogsTeamAudit.php) extends the existing
// spatie/laravel-activitylog usage under a distinct 'team-audit' log name, separate from the
// SSH/deployment command-output activity records the same table already stores (see
// bootstrap/helpers/remoteProcess.php and ActivityController) - these tests exist specifically to
// prove the two streams don't mix and that sensitive fields never reach the audit log.

it('logs a create event under the team-audit log name when a Server is created', function () {
    $team = Team::factory()->create();

    $server = Server::factory()->create(['team_id' => $team->id, 'name' => 'my-server']);

    $activity = Activity::inLog('team-audit')->where('subject_id', $server->id)->where('subject_type', Server::class)->first();

    expect($activity)->not->toBeNull()
        ->and($activity->event)->toBe('created')
        ->and($activity->properties->get('team_id'))->toBe($team->id)
        ->and($activity->properties->get('attributes')['name'])->toBe('my-server');
});

it('logs only the allow-listed attributes when a Server is updated, not internal fields', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $server->update(['name' => 'renamed-server', 'is_validating' => true]);

    $activity = Activity::inLog('team-audit')
        ->where('subject_id', $server->id)
        ->where('subject_type', Server::class)
        ->where('event', 'updated')
        ->first();

    expect($activity)->not->toBeNull();
    $logged = $activity->properties->get('attributes');
    expect($logged)->toHaveKey('name')
        ->and($logged)->not->toHaveKey('is_validating');
});

it('does not create a team-audit entry when only a non-allow-listed attribute changes', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $beforeCount = Activity::inLog('team-audit')->where('subject_id', $server->id)->count();

    $server->update(['is_validating' => true]);

    $afterCount = Activity::inLog('team-audit')->where('subject_id', $server->id)->count();
    expect($afterCount)->toBe($beforeCount);
});

it('logs Application changes without ever including a real secret field', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first();

    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'name' => 'my-app',
        'http_basic_auth_password' => 'SECRET-PASSWORD',
        'manual_webhook_secret_github' => 'SECRET-WEBHOOK',
        'docker_compose_raw' => 'SECRET-COMPOSE-CONTENTS',
    ]);

    $activity = Activity::inLog('team-audit')
        ->where('subject_id', $application->id)
        ->where('subject_type', Application::class)
        ->first();

    expect($activity)->not->toBeNull();
    $logged = $activity->properties->get('attributes');
    expect($logged)->toHaveKey('name')
        ->and($logged)->not->toHaveKey('http_basic_auth_password')
        ->and($logged)->not->toHaveKey('manual_webhook_secret_github')
        ->and($logged)->not->toHaveKey('docker_compose_raw');

    // Belt and suspenders: the secrets must not appear anywhere in the stored JSON, not just
    // under the expected key - guards against a future field rename slipping past the allow-list
    // check above while still leaking the value under a different key.
    $rawProperties = json_encode($activity->properties);
    expect($rawProperties)->not->toContain('SECRET-PASSWORD')
        ->and($rawProperties)->not->toContain('SECRET-WEBHOOK')
        ->and($rawProperties)->not->toContain('SECRET-COMPOSE-CONTENTS');
});

// Regression coverage for a real bug caught by the full suite, not written speculatively: Server
// (unlike Application/Project) never casts team_id to int, and the API's create path
// (getTeamIdFromToken(), used by ServersController::create_server()) stores it as a string
// pulled from token data - LogsTeamAudit::resolveAuditTeamId()'s strict ?int return type
// crashed with a TypeError the first time a Server was created through the real API rather than
// a factory (which always sets team_id from $team->id, already a real int, so this path was
// never exercised by the other tests in this file).
it('does not crash when team_id is stored as a string, not an int', function () {
    $team = Team::factory()->create();

    $server = new Server([
        'name' => 'string-team-id-server',
        'ip' => '203.0.113.20',
        'port' => 22,
        'user' => 'root',
        'private_key_id' => 1,
    ]);
    $server->team_id = (string) $team->id;
    $server->save();

    $activity = Activity::inLog('team-audit')->where('subject_id', $server->id)->where('subject_type', Server::class)->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties->get('team_id'))->toBe($team->id)
        ->and($activity->properties->get('team_id'))->toBeInt();
});

it('scopes the logged team_id to the resource\'s real owning team, not the acting user\'s session team', function () {
    $resourceTeam = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    $server = Server::factory()->create(['team_id' => $resourceTeam->id]);

    $activity = Activity::inLog('team-audit')->where('subject_id', $server->id)->where('subject_type', Server::class)->first();

    expect($activity->properties->get('team_id'))->toBe($resourceTeam->id)
        ->and($activity->properties->get('team_id'))->not->toBe($otherTeam->id);
});
