<?php

declare(strict_types=1);

use App\Jobs\SendWebhookJob;
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

    (new SendWebhookJob(['event' => 'test'], 'https://example.com/webhook'))->handle();

    // SafeWebhookUrl only blocks loopback/link-local hosts when the URL's host is a literal
    // IP - it can't resolve a hostname's real target. Without withoutRedirecting(), a webhook
    // pointed at an attacker-controlled domain that 302s to 169.254.169.254 (cloud metadata)
    // or an internal address would have that redirect silently followed (Guzzle's default).
    expect($capturedOptions['allow_redirects'])->toBe(false);
});

it('still sends the payload to the configured URL', function () {
    Http::fake();

    (new SendWebhookJob(['event' => 'deployment.success'], 'https://example.com/webhook'))->handle();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/webhook'
            && $request->data() === ['event' => 'deployment.success'];
    });
});

it('never sends when the URL fails SafeWebhookUrl validation', function () {
    Http::fake();

    (new SendWebhookJob(['event' => 'test'], 'http://127.0.0.1/webhook'))->handle();

    Http::assertNothingSent();
});

it('signs the request with an HMAC-SHA256 header when a signing secret is set', function () {
    Http::fake();

    $payload = ['event' => 'deployment.success', 'data' => ['id' => 1]];
    $secret = 'test-signing-secret';

    (new SendWebhookJob($payload, 'https://example.com/webhook', $secret))->handle();

    $expectedSignature = 'sha256='.hash_hmac('sha256', json_encode($payload), $secret);

    Http::assertSent(function ($request) use ($expectedSignature) {
        return $request->hasHeader('X-Coolify-Signature-256', $expectedSignature);
    });
});

it('omits the signature header when no signing secret is set', function () {
    Http::fake();

    (new SendWebhookJob(['event' => 'test'], 'https://example.com/webhook'))->handle();

    Http::assertSent(function ($request) {
        return ! $request->hasHeader('X-Coolify-Signature-256');
    });
});

it('signs the exact raw body bytes sent, not a re-encoding of the payload', function () {
    Http::fake();

    $payload = ['b' => 2, 'a' => 1];
    $secret = 'another-secret';

    (new SendWebhookJob($payload, 'https://example.com/webhook', $secret))->handle();

    Http::assertSent(function ($request) use ($secret) {
        $expected = 'sha256='.hash_hmac('sha256', $request->body(), $secret);

        return $request->header('X-Coolify-Signature-256')[0] === $expected;
    });
});
