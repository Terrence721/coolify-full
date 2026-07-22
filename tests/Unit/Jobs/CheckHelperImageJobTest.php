<?php

declare(strict_types=1);

use App\Jobs\CheckHelperImageJob;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('never calls out to upstream\'s CDN and leaves the helper version pinned', function () {
    // This fork has no CDN/upgrade pipeline of its own (see RELEASE.md) - the helper
    // image version stays pinned to config('constants.coolify.helper_version') instead
    // of being bumped from upstream's version feed. Disabled entirely.
    $settings = InstanceSettings::forceCreate(['id' => 0, 'helper_version' => '1.0.14']);
    Http::fake();

    (new CheckHelperImageJob)->handle();

    Http::assertNothingSent();
    expect($settings->fresh()->helper_version)->toBe('1.0.14');
});
