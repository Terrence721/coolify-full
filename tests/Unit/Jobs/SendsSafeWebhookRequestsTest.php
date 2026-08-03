<?php

declare(strict_types=1);

use App\Jobs\Concerns\SendsSafeWebhookRequests;
use App\Jobs\SendMessageToDiscordJob;
use App\Jobs\SendMessageToSlackJob;
use App\Jobs\SendWebhookJob;
use Tests\TestCase;

uses(TestCase::class);

// Structural guarantee, not just today's behavior: all 3 outbound webhook jobs must share the
// same SendsSafeWebhookRequests trait (validate-then-send-without-redirects), not their own
// independent copies of the same logic - the exact drift this refactor closed.
it('is used by all three outbound webhook jobs', function () {
    expect(class_uses(SendWebhookJob::class))->toContain(SendsSafeWebhookRequests::class);
    expect(class_uses(SendMessageToSlackJob::class))->toContain(SendsSafeWebhookRequests::class);
    expect(class_uses(SendMessageToDiscordJob::class))->toContain(SendsSafeWebhookRequests::class);
});
