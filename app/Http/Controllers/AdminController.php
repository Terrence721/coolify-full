<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function index(Request $request): Response
    {
        if (! isCloud() && ! isDev()) {
            abort(403);
        }
        $this->authorizeAdminAccess();

        $search = (string) $request->query('search', '');
        $foundUsers = [];

        if ($search !== '') {
            $foundUsers = User::where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })->get()->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]);
        }

        return Inertia::render('Admin/Index', [
            'name' => Auth::user()->name,
            'email' => Auth::user()->email,
            'impersonating' => (bool) session('impersonating'),
            'search' => $search,
            'foundUsers' => $foundUsers,
            'backUrl' => route('admin.back'),
            'switchUserUrl' => route('admin.switch-user'),
        ]);
    }

    public function back(): RedirectResponse
    {
        $this->authorizeAdminAccess();

        if (session('impersonating')) {
            session()->forget('impersonating');
            $user = User::find(0);
            $teamToSwitchTo = $user->teams->first();
            Auth::login($user);
            refreshSession($teamToSwitchTo);

            return redirect()->route('admin.index');
        }

        return redirect()->route('admin.index');
    }

    public function switchUser(Request $request): RedirectResponse
    {
        $this->authorizeRootOnly();

        $userId = (int) $request->input('user_id');
        session(['impersonating' => true]);
        $user = User::find($userId);
        if (! $user) {
            abort(404);
        }
        $teamToSwitchTo = $user->teams->first();
        Auth::login($user);
        refreshSession($teamToSwitchTo);

        return redirect()->route('dashboard');
    }

    private function authorizeAdminAccess(): void
    {
        if (! Auth::check() || (Auth::id() !== 0 && ! session('impersonating'))) {
            abort(403);
        }
    }

    private function authorizeRootOnly(): void
    {
        if (! Auth::check() || Auth::id() !== 0) {
            abort(403);
        }
    }
}
