<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    use AuthorizesRequests;

    private const ADMIN_VIEW_USER_LIMIT = 20;

    public function index(Request $request): Response
    {
        $team = currentTeam();
        $user = $request->user();

        $canDelete = $user->can('delete', $team);
        $deletionBlockedReason = null;
        $blockingResources = [];

        if ($canDelete) {
            if (data_get(session('currentTeam'), 'id') === 0) {
                $deletionBlockedReason = 'default-team';
            } elseif ($user->teams()->count() === 1 || $user->currentTeam()->personal_team) {
                $deletionBlockedReason = 'last-team';
            } elseif (! $team->isEmpty()) {
                $deletionBlockedReason = 'not-empty';
                $blockingResources = [
                    'projects' => $team->projects()->pluck('name'),
                    'servers' => $team->servers()->pluck('name'),
                    'privateKeys' => $team->privateKeys()->pluck('name'),
                    'sources' => $team->sources()->pluck('name'),
                ];
            }
        }

        return Inertia::render('Team/Index', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'description' => $team->description,
            ],
            'canUpdate' => $user->can('update', $team),
            'canDelete' => $canDelete,
            'deletionBlockedReason' => $deletionBlockedReason,
            'blockingResources' => $blockingResources,
            'updateUrl' => route('team.update'),
            'deleteUrl' => route('team.destroy'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $team = currentTeam();
        $this->authorize('update', $team);

        $validated = Validator::make(
            $request->all(),
            [
                'name' => ValidationPatterns::nameRules(),
                'description' => ValidationPatterns::descriptionRules(),
            ],
            ValidationPatterns::combinedMessages(),
        )->validate();

        $team->update($validated);
        refreshSession();

        return back()->with('success', 'Team updated.');
    }

    public function destroy(): RedirectResponse
    {
        $currentTeam = currentTeam();
        $this->authorize('delete', $currentTeam);
        $currentTeam->delete();

        $currentTeam->members->each(function ($user) use ($currentTeam) {
            if ($user->id === Auth::id()) {
                return;
            }
            $user->teams()->detach($currentTeam);
            $session = DB::table('sessions')->where('user_id', $user->id)->first();
            if ($session) {
                DB::table('sessions')->where('id', $session->id)->delete();
            }
        });

        refreshSession();

        return redirect()->route('team.index');
    }

    public function adminView(Request $request): Response|RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $search = (string) $request->query('search', '');
        $query = User::where('id', '!=', $request->user()->id);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->get();
        $lotsOfUsers = $search === '' && $users->count() > self::ADMIN_VIEW_USER_LIMIT;
        if ($lotsOfUsers) {
            $users = $users->take(self::ADMIN_VIEW_USER_LIMIT);
        }

        return Inertia::render('Team/AdminView', [
            'search' => $search,
            'users' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->values(),
            'lotsOfUsers' => $lotsOfUsers,
            'deleteUserUrl' => route('team.admin-view.delete-user'),
        ]);
    }

    public function adminDeleteUser(Request $request): RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        if (! verifyPasswordConfirmation($request->input('password'))) {
            return back()->with('error', 'The provided password is incorrect.');
        }

        $user = User::find($request->input('id'));
        if (! $user) {
            return back()->with('error', 'User not found');
        }

        $user->delete();

        return back()->with('success', 'User deleted.');
    }
}
