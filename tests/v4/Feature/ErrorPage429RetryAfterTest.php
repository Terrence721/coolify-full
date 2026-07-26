<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

function renderNormalized429(Throwable $exception): string
{
    return preg_replace('/\s+/', ' ', view('errors.429', ['exception' => $exception])->render());
}

it('shows the real Retry-After seconds when a ThrottleRequestsException carries one', function () {
    $exception = new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => 37]);

    expect(renderNormalized429($exception))->toContain('Please wait 37 seconds before trying again.');
});

it('uses singular "second" when Retry-After is exactly 1', function () {
    $exception = new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => 1]);

    expect(renderNormalized429($exception))->toContain('Please wait 1 second before trying again.');
});

it('falls back to the generic wording when no Retry-After header is available', function () {
    expect(renderNormalized429(new Exception('test error message')))
        ->toContain('Please wait a few seconds before trying again.');
});
