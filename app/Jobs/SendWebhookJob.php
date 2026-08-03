<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\SendsSafeWebhookRequests;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWebhookJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SendsSafeWebhookRequests, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 5;

    public int $backoff = 10;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 5;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
        public string $webhookUrl
    ) {
        $this->onQueue('high');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (! $this->isSafeWebhookUrl($this->webhookUrl)) {
            return;
        }

        if (isDev()) {
            ray('Sending webhook notification', [
                'url' => $this->webhookUrl,
                'payload' => $this->payload,
            ]);
        }

        $response = $this->sendWebhookRequest($this->webhookUrl, $this->payload);

        if (isDev()) {
            ray('Webhook response', [
                'status' => $response->status(),
                'body' => $response->body(),
                'successful' => $response->successful(),
            ]);
        }
    }
}
