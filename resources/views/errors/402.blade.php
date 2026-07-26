<x-layout-simple>
<div class="flex flex-col items-center justify-center min-h-screen">
    <div>
        <p class="font-mono font-semibold text-7xl dark:text-warning">402</p>
        <h1 class="mt-4 font-bold tracking-tight dark:text-white">Payment required.</h1>
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
