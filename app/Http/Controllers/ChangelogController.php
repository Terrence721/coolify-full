<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\PullChangelog;
use App\Services\ChangelogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The "What's New" changelog widget — the one genuinely-missing piece of the otherwise-orphaned
 * App\Livewire\SettingsDropdown (its zoom/width-toggle half was already dead code with no UI
 * calling it on either the Livewire or React side; theme switching already lives inline in
 * navbar.blade.php). Plain JSON endpoints rather than Inertia::render(), since this is a global
 * widget fetched on-demand from any page's topbar, not a page navigation — matching the
 * established `fetch()`-for-non-page-data convention (see DatabaseImportTab.jsx).
 */
class ChangelogController extends Controller
{
    public function entries(): JsonResponse
    {
        $user = auth()->user();
        $service = app(ChangelogService::class);

        return response()->json([
            'entries' => $service->getEntriesForUser($user)->values(),
            'unreadCount' => $service->getUnreadCountForUser($user),
            'currentVersion' => 'v'.config('constants.coolify.version'),
        ]);
    }

    public function markAsRead(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'identifier' => 'required|string',
        ])->validate();

        app(ChangelogService::class)->markAsReadForUser($validated['identifier'], auth()->user());

        return response()->json(['success' => true]);
    }

    public function markAllAsRead(): JsonResponse
    {
        app(ChangelogService::class)->markAllAsReadForUser(auth()->user());

        return response()->json(['success' => true]);
    }

    public function manualFetch(): JsonResponse
    {
        if (! isDev()) {
            abort(404);
        }

        PullChangelog::dispatch();

        return response()->json(['success' => true]);
    }
}
