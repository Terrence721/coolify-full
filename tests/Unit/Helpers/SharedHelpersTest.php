<?php

declare(strict_types=1);

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
