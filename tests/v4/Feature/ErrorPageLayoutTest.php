<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Found live 2026-07-26 while manually checking the app: hitting a real 419 (CSRF token
// mismatch) rendered as a bare, unstyled fragment and Chrome DevTools flagged "Page layout may
// be unexpected due to Quirks Mode". Root cause: every view in resources/views/errors/ does
// `@extends('layouts.base')` with raw, un-sectioned content instead of the working
// `<x-layout-simple>` component every real auth page (login, register, etc.) uses. Since
// layouts.base's own `@section('body')...@show` is a complete, self-contained body with no
// `@yield` for page content, the un-sectioned child content prints to the output buffer
// immediately - before the parent's own `<!DOCTYPE html>` - so the final response has the page
// content *before* the doctype, which is genuinely invalid HTML and forces the browser into
// Quirks Mode (confirmed live: document.compatMode === 'BackCompat', document.doctype === null).

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders every error page with a real doctype first, not a bare fragment', function (string $code) {
    $html = view("errors.{$code}", ['exception' => new Exception('test error message')])->render();
    $trimmed = ltrim($html);

    expect(strtolower(substr($trimmed, 0, 15)))->toBe('<!doctype html>');
})->with([
    '400', '401', '402', '403', '404', '419', '429', '500', '503',
]);
