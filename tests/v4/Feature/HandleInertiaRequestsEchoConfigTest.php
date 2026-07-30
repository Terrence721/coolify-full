<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// Regression coverage for a real bug found 2026-07-30 (local HTTPS dev URL, issue #84):
// echo.js hardcoded forceTLS: false, so Echo/Pusher-js always tried a plain ws:// connection
// to Soketi regardless of the page's own scheme - browsers block that as mixed content from an
// HTTPS page, same rule as an HTTP <script> tag. getRealtime() also always returned Soketi's
// real port (6001, plain ws only) even when the request was secure, but nothing terminates TLS
// there (see docker/https-proxy/nginx.conf's dedicated 6443 listener). TrustProxies already
// trusts X-Forwarded-Proto (see PR #82/#83's Docker-version-message fixes for the same proxy
// trust mechanism), so a real nginx-fronted HTTPS request correctly sets $request->secure().
beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('shares the plain ws port and forceTLS: false for a normal HTTP request', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    // getRealtime() only returns a real port when the current request's own host includes one
    // (see its own pre-existing "no port -> null" branch) - the test client's default host has
    // no port, so this forces one by passing a full absolute URL directly: Symfony's
    // Request::create() derives scheme/host/port from the URI string itself, overriding any
    // HTTP_HOST passed separately in the server bag. Matches every real dev URL (e.g. :8000).
    $this->actingAs($user)->withSession(['currentTeam' => $team]);
    $response = $this->get('http://localhost:8000'.route('dashboard', absolute: false));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('echo.port', '6001')
        ->where('echo.forceTLS', false)
    );
});

it('shares the TLS-terminated port and forceTLS: true when the request arrives over HTTPS (via X-Forwarded-Proto)', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'admin']);

    $this->actingAs($user)->withSession(['currentTeam' => $team]);
    $response = $this
        ->withHeader('X-Forwarded-Proto', 'https')
        ->get('https://coolify-full.localhost:8443'.route('dashboard', absolute: false));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('echo.port', '6443')
        ->where('echo.forceTLS', true)
    );
});
