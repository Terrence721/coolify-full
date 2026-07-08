<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TeamInvitation;
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
            } elseif ($team->subscription) {
                $deletionBlockedReason = 'has-subscription';
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
            'subscriptionUrl' => route('subscription.show'),
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
}
