<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Rules\SafeWebhookUrl;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Shared send-time guard for every outbound webhook/notification job. Re-validates the URL with
 * SafeWebhookUrl independently of whatever validation ran when it was saved (it could have been
 * repointed by DNS since then), and centralizes withoutRedirecting() on the actual request, so a
 * future call site can't independently forget either protection.
 */
trait SendsSafeWebhookRequests
{
    private function isSafeWebhookUrl(string $webhookUrl): bool
    {
        $validator = Validator::make(
            ['webhook_url' => $webhookUrl],
            ['webhook_url' => ['required', 'url', new SafeWebhookUrl]]
        );

        if ($validator->fails()) {
            Log::warning(static::class.': blocked unsafe webhook URL', [
                'url' => $webhookUrl,
                'errors' => $validator->errors()->all(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendWebhookRequest(string $webhookUrl, array $payload): Response
    {
        return Http::withoutRedirecting()->post($webhookUrl, $payload);
    }
}
