<?php

declare(strict_types=1);

namespace App\Actions\Server;

use Lorisleiva\Actions\Concerns\AsAction;

class UpdateCoolify
{
    use AsAction;

    public function handle(bool $manual_update = false): void
    {
        // This fork has no CDN/upgrade pipeline of its own (see RELEASE.md) - upstream's
        // self-update mechanism would download and execute coollabsio's own upgrade.sh
        // against a managed server, which isn't compatible with this codebase. Disabled
        // entirely; releases here are pulled via `git pull` + rebuild instead.
    }
}
