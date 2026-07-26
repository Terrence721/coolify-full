<x-layout-simple>
@php
    // ThrottleRequestsException (thrown by Laravel's RateLimiter middleware) carries the exact
    // remaining wait time in a Retry-After header - surface that real number instead of a vague
    // "a few seconds" guess. Other 429 sources (or a plain Exception, e.g. in tests/snapshots)
    // won't have this method/header, so fall back to the generic wording.
    $retryAfter = method_exists($exception, 'getHeaders') ? ($exception->getHeaders()['Retry-After'] ?? null) : null;
@endphp
<div class="flex flex-col items-center justify-center min-h-screen">
    <div>
        <p class="font-mono font-semibold text-7xl dark:text-warning">429</p>
        <h1 class="mt-4 font-bold tracking-tight dark:text-white">Woah, slow down there!</h1>
        @if ($retryAfter)
            <p class="text-base leading-7 dark:text-neutral-400 text-black">You're making too many requests. Please
                wait {{ $retryAfter }} second{{ (int) $retryAfter === 1 ? '' : 's' }} before trying again.
            </p>
        @else
            <p class="text-base leading-7 dark:text-neutral-400 text-black">You're making too many requests. Please wait a
                few
                seconds before trying again.
            </p>
        @endif
        <div class="flex items-center mt-10 gap-x-2">
            <a href="{{ url()->previous() }}" class="button">Go back</a>
            <a href="{{ route('dashboard') }}" class="button">Dashboard</a>
            <a target="_blank" class="text-xs" href="{{ config('constants.urls.contact') }}">Contact
                support
                <x-external-link />
            </a>
        </div>
    </div>
</div>
</x-layout-simple>
