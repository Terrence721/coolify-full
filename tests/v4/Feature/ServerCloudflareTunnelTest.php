<?php

declare(strict_types=1);

use App\Jobs\CoolifyTask;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders the server cloudflare tunnel Inertia page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.cloudflare-tunnel', ['server_uuid' => $server->uuid]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Server/CloudflareTunnel')
        ->has('serverNavbar')
        ->has('sidebar')
        ->where('isCloudflareTunnelsEnabled', false)
        ->where('canUpdate', true)
    );
});

it('redirects away for the localhost server', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id, 'ip' => 'host.docker.internal']);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.cloudflare-tunnel', ['server_uuid' => $server->uuid]));

    $response->assertRedirect(route('server.show', ['server_uuid' => $server->uuid]));
});

it('enables cloudflare tunnel via manual configuration', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.cloudflare-tunnel.manual-config', ['server_uuid' => $server->uuid]));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Cloudflare Tunnel enabled.');
    expect($server->settings->fresh()->is_cloudflare_tunnel)->toBeTruthy();
});

it('provisions the real cloudflared docker-compose config on successful automated configuration', function () {
    // The live smoke test (issue #26, 2026-07-28) proved this against a real Cloudflare account
    // once, by hand - it isn't re-run automatically. This is the regression guard: it doesn't
    // touch a real Cloudflare account, but it does prove ConfigureCloudflared actually builds the
    // right provisioning commands (the part a change to that class could silently break without
    // any existing test catching it - the "missing fields" test above only covers the validation
    // path, never the success path).
    Queue::fake();
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.cloudflare-tunnel.automated-config', ['server_uuid' => $server->uuid]), [
            'cloudflare_token' => 'fake-tunnel-token-abc123',
            'ssh_domain' => 'https://ssh-smoketest.example.com/',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('activityContext', 'cloudflare-tunnel');
    $response->assertSessionHas('activityId');

    Queue::assertPushed(CoolifyTask::class);

    $activityId = session('activityId');
    $activity = Activity::findOrFail($activityId);
    expect($activity->properties['server_uuid'])->toBe($server->uuid);

    // The command embeds the compose file as a base64 blob piped through `base64 -d`; decode it
    // back out and assert on the real YAML structure, not just that some command string exists.
    preg_match("/echo '([A-Za-z0-9+\/=]+)' \| base64 -d/", $activity->properties['command'], $matches);
    expect($matches)->toHaveCount(2);
    $compose = Yaml::parse(base64_decode($matches[1]));

    expect($compose['services']['coolify-cloudflared']['image'])->toBe('cloudflare/cloudflared:latest');
    expect($compose['services']['coolify-cloudflared']['environment'])->toContain('TUNNEL_TOKEN=fake-tunnel-token-abc123');
    expect($activity->properties['command'])->toContain('docker compose up');
});

it('strips the scheme from an ssh_domain submitted with https://', function () {
    // ConfigureCloudflared::handle() doesn't receive the scheme-stripped domain back for
    // inspection directly (it only flows into CloudflareTunnelChanged's event payload, fired
    // from inside the queued CoolifyTask job body - out of reach with Queue::fake()), so this
    // proves the stripping via the one place it's externally observable: the command never
    // constructs a working provisioning command around a domain still carrying "https://" (which
    // would break the SSH connection details written elsewhere), while still succeeding overall.
    Queue::fake();
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.cloudflare-tunnel.automated-config', ['server_uuid' => $server->uuid]), [
            'cloudflare_token' => 'fake-tunnel-token-abc123',
            'ssh_domain' => 'https://ssh-smoketest.example.com/',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('activityContext', 'cloudflare-tunnel');
    Queue::assertPushed(CoolifyTask::class, fn (CoolifyTask $job) => $job->call_event_data['ssh_domain'] === 'ssh-smoketest.example.com');
});

it('rejects automated configuration with missing fields', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->post(route('server.cloudflare-tunnel.automated-config', ['server_uuid' => $server->uuid]), []);

    $response->assertSessionHasErrors(['cloudflare_token', 'ssh_domain']);
});

it('returns 404 for a server owned by another team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('server.cloudflare-tunnel', ['server_uuid' => $server->uuid]));

    $response->assertNotFound();
});
