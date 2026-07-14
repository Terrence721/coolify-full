<?php

namespace App\Http\Middleware;

use App\Services\ChangelogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app-inertia';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $team = $user ? currentTeam() : null;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'isAdmin' => $user->isAdmin(),
                ] : null,
            ],
            'currentTeam' => $team ? [
                'id' => $team->id,
                'name' => $team->name,
                'isAnyNotificationEnabled' => (bool) $team->isAnyNotificationEnabled(),
            ] : null,
            'changelog' => $user ? [
                'unreadCount' => app(ChangelogService::class)->getUnreadCountForUser($user),
                'currentVersion' => 'v'.config('constants.coolify.version'),
            ] : null,
            'permissions' => [
                'isCloud' => isCloud(),
                'isInstanceAdmin' => isInstanceAdmin(),
                'isDev' => isDev(),
                'canAccessTerminal' => $user ? Gate::forUser($user)->allows('canAccessTerminal') : false,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'info' => $request->session()->get('info'),
                'activityId' => $request->session()->get('activityId'),
                'activityContext' => $request->session()->get('activityContext'),
                'domainConflicts' => $request->session()->get('domainConflicts'),
                'showDomainConflictModal' => $request->session()->get('showDomainConflictModal'),
                'requiredPort' => $request->session()->get('requiredPort'),
                'showPortWarningModal' => $request->session()->get('showPortWarningModal'),
            ],
            'echo' => $user ? [
                'key' => config('constants.pusher.app_key') ?: 'coolify',
                'host' => config('constants.pusher.host') ?: $request->getHost(),
                'port' => getRealtime(),
            ] : null,
        ];
    }
}
