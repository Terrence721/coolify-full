<?php

declare(strict_types=1);

use App\Jobs\SendMessageToSlackJob;
use App\Notifications\Dto\SlackMessage;
use App\Rules\SafeWebhookUrl;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

// SafeWebhookUrl now resolves hostnames via DNS to catch a domain pointed directly at a blocked
// IP - stub it here so the suite stays network-independent instead of resolving hooks.slack.com/
// example.com for real on every run.
beforeEach(function () {
    SafeWebhookUrl::resolveHostUsing(fn (string $host) => ['203.0.113.10']);
});

afterEach(function () {
    SafeWebhookUrl::resolveHostUsing(null);
});

it('disables redirect-following when sending to a real Slack webhook URL', function () {
    $capturedOptions = null;
    Http::fake(function ($request, $options) use (&$capturedOptions) {
        $capturedOptions = $options;

        return Http::response('ok', 200);
    });

    $message = new SlackMessage('Deployment succeeded', 'All good', SlackMessage::successColor());
    (new SendMessageToSlackJob($message, 'https://hooks.slack.com/services/T00/B00/XXX'))->handle();

    expect($capturedOptions['allow_redirects'])->toBe(false);
});

it('disables redirect-following on the Mattermost fallback path too', function () {
    // Any non-hooks.slack.com URL falls through to the Mattermost-compatible payload -
    // this is the more attacker-relevant path, since a user can point it at any URL.
    $capturedOptions = null;
    Http::fake(function ($request, $options) use (&$capturedOptions) {
        $capturedOptions = $options;

        return Http::response('ok', 200);
    });

    $message = new SlackMessage('Deployment succeeded', 'All good', SlackMessage::successColor());
    (new SendMessageToSlackJob($message, 'https://example.com/mattermost-hook'))->handle();

    expect($capturedOptions['allow_redirects'])->toBe(false);
});

it('sends the Slack-formatted payload only to a real hooks.slack.com URL', function () {
    Http::fake();

    $message = new SlackMessage('Deployment succeeded', 'All good', SlackMessage::successColor());
    (new SendMessageToSlackJob($message, 'https://hooks.slack.com/services/T00/B00/XXX'))->handle();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://hooks.slack.com/services/T00/B00/XXX'
            && $request->data()['text'] === 'Deployment succeeded';
    });
});

it('sends the Mattermost-compatible payload to any other URL', function () {
    Http::fake();

    $message = new SlackMessage('Deployment succeeded', 'All good', SlackMessage::successColor());
    (new SendMessageToSlackJob($message, 'https://example.com/mattermost-hook'))->handle();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/mattermost-hook'
            && $request->data()['attachments'][0]['title'] === 'Deployment succeeded';
    });
});

it('never sends when the URL fails SafeWebhookUrl validation', function () {
    Http::fake();

    $message = new SlackMessage('Deployment succeeded', 'All good', SlackMessage::successColor());
    (new SendMessageToSlackJob($message, 'http://127.0.0.1/mattermost-hook'))->handle();

    Http::assertNothingSent();
});
