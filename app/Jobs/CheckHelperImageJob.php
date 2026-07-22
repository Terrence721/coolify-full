<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckHelperImageJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1000;

    public function __construct() {}

    public function handle(): void
    {
        // This fork has no CDN/upgrade pipeline of its own (see RELEASE.md) - the helper
        // image version stays pinned to config('constants.coolify.helper_version')
        // instead of being bumped from upstream's version feed. Disabled entirely.
    }
}
