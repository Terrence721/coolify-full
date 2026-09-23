<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\User\RevokeUserTeamTokens;
use App\Enums\Role;
use App\Models\Team;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;
use Visus\Cuid2\Cuid2;

class TeamController extends Controller
{
    use AuthorizesRequests;

    private const ADMIN_VIEW_USER_LIMIT = 20;

    public function index(Request $request): Response
    {
        $team = currentTeam();

        /** @var User $user */
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
            'canViewAuditLog' => $user->can('viewAuditLog', $team),
            'deletionBlockedReason' => $deletionBlockedReason,
            'blockingResources' => $blockingResources,
            'updateUrl' => route('team.update'),
            'deleteUrl' => route('team.destroy'),
            'auditLogUrl' => route('team.audit-log'),
        ]);
    }

    public function auditLog(): Response
    {
        $team = currentTeam();
        $this->authorize('viewAuditLog', $team);

        // team_id lives in properties, not a real activity_log column - see LogsTeamAudit's
        // tapActivity() and the manual activity() calls elsewhere in this controller, both of
        // which store it the same way.
        $entries = Activity::query()
            ->inLog('team-audit')
            ->where('properties->team_id', $team->id)
            ->with('causer')
            ->latest()
            ->limit(100)
            ->get()
            ->map(function (Activity $activity): array {
                $causer = $activity->causer;

                return [
                    'id' => $activity->id,
                    'event' => $activity->event,
                    'description' => $activity->description,
                    'causerName' => $causer instanceof User ? $causer->name : 'System',
                    'subjectType' => $activity->subject_type ? class_basename($activity->subject_type) : null,
                    'changes' => $activity->changes(),
                    'createdAt' => $activity->created_at?->toIso8601String(),
                ];
            });

        return Inertia::render('Team/AuditLog', [
            'entries' => $entries,
        ]);
    }

    public function switch(Request $request): RedirectResponse
    {
        $validated = Validator::make($request->all(), [
            'team_id' => ['required', 'integer'],
        ])->validate();

        /** @var User $user */
        $user = $request->user();

        // Scoped through the user's own teams() relation, not Team::find() - switching to any
        // arbitrary team_id would let a user move their session onto a team they don't belong to.
        $team = $user->teams()->where('teams.id', $validated['team_id'])->first();

        if (! $team) {
            return back()->with('error', 'You are not a member of that team.');
        }

        refreshSession($team);

        return redirect()->route('dashboard');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = Validator::make(
            $request->all(),
            [
                'name' => ValidationPatterns::nameRules(),
                'description' => ValidationPatterns::descriptionRules(),
            ],
            ValidationPatterns::combinedMessages(),
        )->validate();

        $team = Team::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'personal_team' => false,
        ]);
        /** @var User $user */
        $user = $request->user();
        $user->teams()->attach($team, ['role' => 'admin']);
        refreshSession($team);

        return redirect()->route('team.index');
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

        /** @var User $authUser */
        $authUser = $request->user();

        $search = (string) $request->query('search', '');
        $query = User::where('id', '!=', $authUser->id);

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

    public function memberIndex(): Response|RedirectResponse
    {
        $team = currentTeam();
        $user = Auth::user();

        if (! $user instanceof User) {
            return redirect()->route('dashboard');
        }

        $canManageMembers = $user->can('manageMembers', $team);
        $canManageInvitations = $user->can('manageInvitations', $team);
        $canViewAuditLog = $user->can('viewAuditLog', $team);

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
            'currentUserRole' => data_get($team->members()->whereKey($user->id)->first(), 'pivot.role'),
            'canManageMembers' => $canManageMembers,
            'canManageInvitations' => $canManageInvitations,
            'canViewAuditLog' => $canViewAuditLog,
            'auditLogUrl' => route('team.audit-log'),
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

        /** @var User $authUser */
        $authUser = Auth::user();

        $member = User::findOrFail($member_id);
        $targetRole = Role::from($validated['role']);
        $currentUserRole = Role::from($authUser->role());
        $memberPivotRole = $member->teams()
            ->newPivotStatement()
            ->where('team_id', $team->id)
            ->where('user_id', $member->id)
            ->value('role');

        if ($memberPivotRole === null) {
            return back()->with('error', 'This user is not a member of your team.');
        }

        $memberRole = Role::from((string) $memberPivotRole);

        if ($currentUserRole->lt($targetRole) || $memberRole->gt($currentUserRole)) {
            return back()->with('error', 'You are not authorized to perform this action.');
        }

        $member->teams()->updateExistingPivot($team->id, ['role' => $targetRole->value]);
        RevokeUserTeamTokens::forUserTeam($member, $team->id);

        activity()
            ->useLog('team-audit')
            ->causedBy(Auth::user())
            ->performedOn($team)
            ->withProperties([
                'team_id' => $team->id,
                'target_user_id' => $member->id,
                'target_user_name' => $member->name,
                'old_role' => $memberPivotRole,
                'new_role' => $targetRole->value,
            ])
            ->event('member.role_updated')
            ->log("Changed {$member->name}'s role from {$memberPivotRole} to {$targetRole->value}");

        return back();
    }

    public function removeMember(int $member_id): RedirectResponse
    {
        $team = currentTeam();
        $this->authorize('manageMembers', $team);

        /** @var User $authUser */
        $authUser = Auth::user();

        $member = User::findOrFail($member_id);
        $currentUserRole = Role::from($authUser->role());
        $memberPivotRole = $member->teams()
            ->newPivotStatement()
            ->where('team_id', $team->id)
            ->where('user_id', $member->id)
            ->value('role');

        if ($memberPivotRole === null) {
            return back()->with('error', 'This user is not a member of your team.');
        }

        $memberRole = Role::from((string) $memberPivotRole);

        if ($currentUserRole->lt(Role::ADMIN) || $memberRole->gt($currentUserRole)) {
            return back()->with('error', 'You are not authorized to perform this action.');
        }

        $member->teams()->detach($team);
        RevokeUserTeamTokens::forUserTeam($member, $team->id);
        Cache::forget("team:{$member->id}");
        Cache::forget("user:{$member->id}:team:{$team->id}");

        activity()
            ->useLog('team-audit')
            ->causedBy(Auth::user())
            ->performedOn($team)
            ->withProperties([
                'team_id' => $team->id,
                'target_user_id' => $member->id,
                'target_user_name' => $member->name,
                'role' => $memberPivotRole,
            ])
            ->event('member.removed')
            ->log("Removed {$member->name} ({$memberPivotRole}) from the team");

        return back();
    }

    public function sendInvitation(Request $request): RedirectResponse
    {
        $team = currentTeam();
        $this->authorize('manageInvitations', $team);

        /** @var User $authUser */
        $authUser = Auth::user();

        $validated = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'role' => ['required', 'string', 'in:owner,admin,member'],
            'via' => ['required', 'string', 'in:email,link'],
        ])->validate();

        $userRole = $authUser->role();
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
            if (isCloud()) {
                $user->sendVerificationEmail();
            } else {
                $user->markEmailAsVerified();
            }
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
            Log::error('Unhandled exception in sendInvitation().', ['error' => $e->getMessage()]);
            $message = $e->getCode() === '23505' ? 'Invitation already sent.' : $e->getMessage();

            return back()->with('error', $message);
        }

        activity()
            ->useLog('team-audit')
            ->causedBy($authUser)
            ->performedOn($team)
            ->withProperties([
                'team_id' => $team->id,
                'invited_email' => $email,
                'role' => $validated['role'],
            ])
            ->event('member.invited')
            ->log("Invited {$email} to join as {$validated['role']}");

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
