<?php

declare(strict_types=1);

use App\Jobs\CheckForUpdatesJob;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('never calls out to upstream\'s CDN', function () {
    // This fork has no CDN/upgrade pipeline of its own (see RELEASE.md) - checking
    // upstream's coollabsio CDN for a "latest version" would be misleading here, since
    // there's no self-update mechanism to actually deliver it. Disabled entirely.
    InstanceSettings::forceCreate(['id' => 0, 'new_version_available' => false]);
    Http::fake();

    (new CheckForUpdatesJob)->handle();

    Http::assertNothingSent();
    expect(InstanceSettings::find(0)->new_version_available)->toBeFalsy();
});
