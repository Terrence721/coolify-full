<?php

declare(strict_types=1);

use App\Jobs\SendMessageToDiscordJob;
use App\Notifications\Dto\DiscordMessage;
use App\Rules\SafeWebhookUrl;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

// SafeWebhookUrl now resolves hostnames via DNS to catch a domain pointed directly at a blocked
// IP - stub it here so the suite stays network-independent instead of resolving example.com for
// real on every run.
beforeEach(function () {
    SafeWebhookUrl::resolveHostUsing(fn (string $host) => ['203.0.113.10']);
});

afterEach(function () {
    SafeWebhookUrl::resolveHostUsing(null);
});

it('disables redirect-following on the outgoing request, closing the SSRF bypass', function () {
    $capturedOptions = null;
    Http::fake(function ($request, $options) use (&$capturedOptions) {
        $capturedOptions = $options;

        return Http::response('ok', 200);
    });

    $message = new DiscordMessage('Deployment succeeded', 'All good', DiscordMessage::successColor());
    (new SendMessageToDiscordJob($message, 'https://example.com/discord-webhook'))->handle();

    expect($capturedOptions['allow_redirects'])->toBe(false);
});

it('sends the message payload to the configured webhook URL', function () {
    Http::fake();

    $message = new DiscordMessage('Deployment succeeded', 'All good', DiscordMessage::successColor());
    (new SendMessageToDiscordJob($message, 'https://example.com/discord-webhook'))->handle();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/discord-webhook';
    });
});

it('never sends when the URL fails SafeWebhookUrl validation', function () {
    Http::fake();

    $message = new DiscordMessage('Deployment succeeded', 'All good', DiscordMessage::successColor());
    (new SendMessageToDiscordJob($message, 'http://127.0.0.1/discord-webhook'))->handle();

    Http::assertNothingSent();
});
