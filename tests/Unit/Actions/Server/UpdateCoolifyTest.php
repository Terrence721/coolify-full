<?php

declare(strict_types=1);

use App\Actions\Server\UpdateCoolify;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('never contacts upstream\'s CDN or runs an upgrade script, for a scheduled run', function () {
    // This fork has no CDN/upgrade pipeline of its own (see RELEASE.md) - upstream's
    // self-update mechanism would download and execute coollabsio's own upgrade.sh
    // against a managed server, which isn't compatible with this codebase. Disabled
    // entirely; releases here are pulled via `git pull` + rebuild instead.
    InstanceSettings::forceCreate(['id' => 0, 'new_version_available' => true]);
    Http::fake();

    UpdateCoolify::run(false);

    Http::assertNothingSent();
});

it('never contacts upstream\'s CDN or runs an upgrade script, for a manual run', function () {
    InstanceSettings::forceCreate(['id' => 0, 'new_version_available' => true]);
    Http::fake();

    UpdateCoolify::run(true);

    Http::assertNothingSent();
});
