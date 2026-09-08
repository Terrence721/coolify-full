<?php

declare(strict_types=1);

use Illuminate\Support\Stringable;

// isBase64Encoded() crashed with a TypeError on genuinely invalid input instead of
// returning false as its own signature promises. base64_decode($str, true) (strict mode)
// returns bool false when the input contains any character outside the base64 alphabet;
// the old implementation passed that false straight into base64_encode(), which requires
// a string argument under strict_types=1 - confirmed live via tinker before this fix.
// 7 call sites across ApplicationsController, ServicesController, and DatabasesController
// all relied on this helper to gracefully reject bad input with a 422, not a 500.

it('returns false for a string with characters outside the base64 alphabet', function () {
    expect(isBase64Encoded('this is not base64 or PEM!!!'))->toBeFalse();
});

it('returns true for a genuinely base64-encoded string', function () {
    expect(isBase64Encoded(base64_encode('hello world')))->toBeTrue();
});

it('returns false for null', function () {
    expect(isBase64Encoded(null))->toBeFalse();
});

it('treats an empty string as validly (trivially) base64-encoded, unchanged pre-existing behavior', function () {
    expect(isBase64Encoded(''))->toBeTrue();
});

// addPreviewDeploymentSuffix() was typed as (string $name, ...), but most real callers
// pass the result of chained Stringable methods (->before(), ->after(),
// ->replaceFirst(), etc.) on docker-compose volume/service strings - PHP does not
// coerce a Stringable object into a scalar string parameter even implicitly, so every
// one of those 13 call sites threw a TypeError under strict_types=1. Confirmed live via
// tinker before this fix.

it('accepts a plain string name and appends the pr suffix', function () {
    expect(addPreviewDeploymentSuffix('my-volume', 5))->toBe('my-volume-pr-5');
});

it('accepts a Stringable name (as returned by chained ->before()/->after() calls) without a TypeError', function () {
    $name = str('prefix-my-volume-suffix')->after('prefix-')->before('-suffix');

    expect($name)->toBeInstanceOf(Stringable::class);
    expect(addPreviewDeploymentSuffix($name, 5))->toBe('my-volume-pr-5');
});

it('returns the name unchanged when pull_request_id is 0', function () {
    expect(addPreviewDeploymentSuffix('my-volume'))->toBe('my-volume');
});

// parseEnvVariable() was typed as (Illuminate\Support\Str|string $value), but every real
// caller passes the return value of replaceVariables(), which is declared to return
// Illuminate\Support\Stringable - a different class from Str. Illuminate\Support\Str is
// a static-only helper, never instantiated, so this type hint could never actually match
// a real caller; passing a Stringable threw a TypeError under strict_types=1.

it('parses a SERVICE_FQDN env key from a Stringable value without a TypeError', function () {
    $value = str('SERVICE_FQDN_UMAMI');

    $result = parseEnvVariable($value);

    expect($result['command']->value())->toBe('FQDN');
    expect($result['forService']->value())->toBe('UMAMI');
});

it('parses a plain string env key without a TypeError', function () {
    $result = parseEnvVariable('SERVICE_BASE64_UMAMI');

    expect($result['command']->value())->toBe('BASE64');
});
