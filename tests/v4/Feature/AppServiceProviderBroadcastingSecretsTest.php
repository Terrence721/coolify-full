<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;

// scripts/install.sh generates real secrets on every standard install - this only fires for a
// deploy that skipped it, or a partial install failure, where these vars silently fall back to
// the literal 'coolify' default (config/broadcasting.php). See issue #150.

function invokeConfigureBroadcastingSecrets(): void
{
    $provider = new AppServiceProvider(app());
    $method = new ReflectionMethod($provider, 'configureBroadcastingSecrets');
    $method->setAccessible(true);
    $method->invoke($provider);
}

it('refuses to boot in production when the broadcasting secret is still the insecure default', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['broadcasting.connections.pusher.secret' => 'coolify']);

    expect(fn () => invokeConfigureBroadcastingSecrets())
        ->toThrow(RuntimeException::class, 'PUSHER_APP_SECRET');
});

it('lists every var still on the default, not just the first one found', function () {
    app()->detectEnvironment(fn () => 'production');
    config([
        'broadcasting.connections.pusher.key' => 'coolify',
        'broadcasting.connections.pusher.secret' => 'coolify',
        'broadcasting.connections.pusher.app_id' => 'real-app-id',
    ]);

    expect(fn () => invokeConfigureBroadcastingSecrets())
        ->toThrow(RuntimeException::class, 'PUSHER_APP_KEY, PUSHER_APP_SECRET');
});

it('boots fine in production once real secrets are set', function () {
    app()->detectEnvironment(fn () => 'production');
    config([
        'broadcasting.connections.pusher.key' => 'a-real-key',
        'broadcasting.connections.pusher.secret' => 'a-real-secret',
        'broadcasting.connections.pusher.app_id' => 'a-real-app-id',
    ]);

    invokeConfigureBroadcastingSecrets();
})->throwsNoExceptions();

it('does not enforce this outside production', function () {
    app()->detectEnvironment(fn () => 'testing');
    config(['broadcasting.connections.pusher.secret' => 'coolify']);

    invokeConfigureBroadcastingSecrets();
})->throwsNoExceptions();
