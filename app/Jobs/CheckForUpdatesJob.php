<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckForUpdatesJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // This fork has no CDN/upgrade pipeline of its own (see RELEASE.md) - checking
        // upstream's CDN for a "latest version" and marking one available here would be
        // misleading, since there's no self-update mechanism to actually deliver it.
        // Disabled entirely; new_version_available is left at its existing value.
    }
}
