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
     *
     * Signs the exact raw bytes being sent, not a separate re-encoding of $payload - encoding it
     * once and sending that same string via withBody() (rather than the ->post($url, $payload)
     * array shorthand, which encodes internally) guarantees the signature a receiver computes
     * over the request body they actually received matches what was signed here, with no risk of
     * a key-ordering/whitespace mismatch between two independent json_encode() calls.
     */
    private function sendWebhookRequest(string $webhookUrl, array $payload, ?string $signingSecret = null): Response
    {
        $body = json_encode($payload);
        $request = Http::withoutRedirecting()->withBody($body, 'application/json');

        if ($signingSecret !== null) {
            $request = $request->withHeaders([
                'X-Coolify-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $signingSecret),
            ]);
        }

        return $request->post($webhookUrl);
    }
}
