<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\User\RevokeUserTeamTokens;
use App\Enums\Role;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Visus\Cuid2\Cuid2;

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

    public function memberIndex(): Response
    {
        $team = currentTeam();
        $user = auth()->user();
        $canManageMembers = $user->can('manageMembers', $team);
        $canManageInvitations = $user->can('manageInvitations', $team);

        return Inertia::render('Team/Member/Index', [
            'members' => $team->members->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => data_get($member, 'pivot.role'),
                'isCurrentUser' => $member->id === $user->id,
                'updateRoleUrl' => route('team.member.update-role', ['member_id' => $member->id]),
                'removeUrl' => route('team.member.remove', ['member_id' => $member->id]),
            ]),
            'currentUserRole' => $user->role(),
            'canManageMembers' => $canManageMembers,
            'canManageInvitations' => $canManageInvitations,
            'isInstanceAdmin' => isInstanceAdmin(),
            'isTransactionalEmailsEnabled' => is_transactional_emails_enabled(),
            'invitations' => $canManageInvitations
                ? TeamInvitation::ownedByCurrentTeam()->get()->map(fn (TeamInvitation $invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'via' => $invitation->via,
                    'role' => $invitation->role,
                    'link' => $invitation->link,
                    'deleteUrl' => route('team.invitation.destroy', ['invitation_id' => $invitation->id]),
                ])
                : [],
            'sendInvitationUrl' => route('team.invitation.send'),
        ]);
    }

    public function updateMemberRole(Request $request, int $member_id): RedirectResponse
    {
        $team = currentTeam();
        $this->authorize('manageMembers', $team);

        $validated = Validator::make($request->all(), [
            'role' => ['required', 'string', 'in:owner,admin,member'],
        ])->validate();

        $member = User::findOrFail($member_id);
        $targetRole = Role::from($validated['role']);
        $currentUserRole = Role::from(auth()->user()->role());
        $memberRole = Role::from((string) $member->teams()
            ->newPivotStatement()
            ->where('team_id', $team->id)
            ->where('user_id', $member->id)
            ->value('role'));

        if ($currentUserRole->lt($targetRole) || $memberRole->gt($currentUserRole)) {
            return back()->with('error', 'You are not authorized to perform this action.');
        }

        $member->teams()->updateExistingPivot($team->id, ['role' => $targetRole->value]);
        RevokeUserTeamTokens::forUserTeam($member, $team->id);

        return back();
    }

    public function removeMember(int $member_id): RedirectResponse
    {
        $team = currentTeam();
        $this->authorize('manageMembers', $team);

        $member = User::findOrFail($member_id);
        $currentUserRole = Role::from(auth()->user()->role());
        $memberRole = Role::from((string) $member->teams()
            ->newPivotStatement()
            ->where('team_id', $team->id)
            ->where('user_id', $member->id)
            ->value('role'));

        if ($currentUserRole->lt(Role::ADMIN) || $memberRole->gt($currentUserRole)) {
            return back()->with('error', 'You are not authorized to perform this action.');
        }

        $member->teams()->detach($team);
        RevokeUserTeamTokens::forUserTeam($member, $team->id);
        Cache::forget("team:{$member->id}");
        Cache::forget("user:{$member->id}:team:{$team->id}");

        return back();
    }

    public function sendInvitation(Request $request): RedirectResponse
    {
        $team = currentTeam();
        $this->authorize('manageInvitations', $team);

        $validated = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'role' => ['required', 'string', 'in:owner,admin,member'],
            'via' => ['required', 'string', 'in:email,link'],
        ])->validate();

        $userRole = auth()->user()->role();
        if (is_null($userRole) || ($userRole === 'member' && in_array($validated['role'], ['admin', 'owner']))) {
            return back()->with('error', 'Members cannot invite admins or owners.');
        }
        if ($userRole === 'admin' && $validated['role'] === 'owner') {
            return back()->with('error', 'Admins cannot invite owners.');
        }

        $email = strtolower($validated['email']);
        $sendEmail = $validated['via'] === 'email';

        if ($team->members()->get()->pluck('email')->contains($email)) {
            return back()->with('error', "{$email} is already a member of {$team->name}.");
        }

        $uuid = (string) new Cuid2(32);
        $link = url('/').config('constants.invitation.link.base_url').$uuid;
        $user = User::whereEmail($email)->first();

        if (is_null($user)) {
            $password = Str::password();
            $user = User::create([
                'name' => str($email)->before('@'),
                'email' => $email,
                'password' => Hash::make($password),
                'force_password_reset' => true,
            ]);
            $token = Crypt::encryptString("{$user->email}@@@{$uuid}@@@{$password}");
            $link = route('auth.link', ['token' => $token]);
        }

        $invitation = TeamInvitation::whereEmail($email)->first();
        if (! is_null($invitation)) {
            if ($invitation->isValid()) {
                return back()->with('error', "Pending invitation already exists for {$email}.");
            }
            $invitation->delete();
        }

        try {
            TeamInvitation::firstOrCreate([
                'team_id' => $team->id,
                'uuid' => $uuid,
                'email' => $email,
                'role' => $validated['role'],
                'link' => $link,
                'via' => $sendEmail ? 'email' : 'link',
            ]);
        } catch (\Throwable $e) {
            $message = $e->getCode() === '23505' ? 'Invitation already sent.' : $e->getMessage();

            return back()->with('error', $message);
        }

        if ($sendEmail) {
            $mail = new MailMessage;
            $mail->view('emails.invitation-link', [
                'team' => $team->name,
                'invitation_link' => $link,
            ]);
            $mail->subject("You have been invited to {$team->name} on ".config('app.name').'.');
            send_user_an_email($mail, $email);

            return back()->with('success', 'Invitation sent via email.');
        }

        return back()->with('success', 'Invitation link generated.');
    }

    public function deleteInvitation(int $invitation_id): RedirectResponse
    {
        $team = currentTeam();
        $this->authorize('manageInvitations', $team);

        $invitation = TeamInvitation::ownedByCurrentTeam()->find($invitation_id);
        if (! $invitation) {
            return back()->with('error', 'Invitation not found.');
        }

        $user = User::whereEmail($invitation->email)->first();
        if (filled($user)) {
            $user->deleteIfNotVerifiedAndForcePasswordReset();
        }

        $invitation->delete();

        return back()->with('success', 'Invitation revoked.');
    }
}
