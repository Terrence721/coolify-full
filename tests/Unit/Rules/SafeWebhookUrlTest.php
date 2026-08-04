<?php

declare(strict_types=1);

use App\Rules\SafeWebhookUrl;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

// SafeWebhookUrl previously only inspected the host when it was a literal IP - a hostname was
// never resolved, so a webhook URL pointing at an attacker-controlled domain that resolves
// straight to 169.254.169.254 (cloud metadata) or an internal address passed validation
// unconditionally, at both save time and every job's send-time re-validation. This is the DNS
// half of the SSRF surface; PR #102/#131 closed the redirect-following half, this closes the
// direct-resolution half. The remaining disclosed gap (DNS rebinding between this check and the
// actual outbound connection) is out of scope - closing that fully needs connection-level IP
// pinning, tracked separately.

afterEach(function () {
    SafeWebhookUrl::resolveHostUsing(null);
});

function validateWebhookUrl(string $url): ValidatorContract
{
    return Validator::make(['url' => $url], ['url' => [new SafeWebhookUrl]]);
}

it('rejects a hostname that resolves to a loopback address', function () {
    SafeWebhookUrl::resolveHostUsing(fn (string $host) => ['127.0.0.1']);

    $validator = validateWebhookUrl('https://attacker-controlled.example/webhook');

    expect($validator->fails())->toBeTrue();
});

it('rejects a hostname that resolves to the cloud metadata link-local range', function () {
    SafeWebhookUrl::resolveHostUsing(fn (string $host) => ['169.254.169.254']);

    $validator = validateWebhookUrl('https://attacker-controlled.example/webhook');

    expect($validator->fails())->toBeTrue();
});

it('rejects a hostname when any one of several resolved IPs is blocked', function () {
    SafeWebhookUrl::resolveHostUsing(fn (string $host) => ['203.0.113.10', '169.254.169.254']);

    $validator = validateWebhookUrl('https://attacker-controlled.example/webhook');

    expect($validator->fails())->toBeTrue();
});

it('allows a hostname that resolves only to public addresses', function () {
    SafeWebhookUrl::resolveHostUsing(fn (string $host) => ['203.0.113.10']);

    $validator = validateWebhookUrl('https://example.com/webhook');

    expect($validator->fails())->toBeFalse();
});

it('allows a hostname that fails to resolve, leaving the actual connection to fail naturally', function () {
    SafeWebhookUrl::resolveHostUsing(fn (string $host) => []);

    $validator = validateWebhookUrl('https://nonexistent-domain.invalid/webhook');

    expect($validator->fails())->toBeFalse();
});

it('still blocks a literal loopback IP directly, unaffected by DNS resolution', function () {
    SafeWebhookUrl::resolveHostUsing(fn (string $host) => ['203.0.113.10']);

    $validator = validateWebhookUrl('http://127.0.0.1/webhook');

    expect($validator->fails())->toBeTrue();
});
