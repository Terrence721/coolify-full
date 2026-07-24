import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

function roleActionsForOwnerViewer(member) {
    if (member.role === 'owner') {
        return [
            { label: 'To Admin', role: 'admin' },
            { label: 'To Member', role: 'member' },
        ];
    }
    if (member.role === 'admin') {
        return [
            { label: 'To Owner', role: 'owner' },
            { label: 'To Member', role: 'member' },
        ];
    }

    return [
        { label: 'To Owner', role: 'owner' },
        { label: 'To Admin', role: 'admin' },
    ];
}

function roleActionsForAdminViewer(member) {
    if (member.role === 'admin') {
        return [{ label: 'To Member', role: 'member' }];
    }
    if (member.role === 'member') {
        return [{ label: 'To Admin', role: 'admin' }];
    }

    return [];
}

function MemberRow({ member, currentUserRole, canManageMembers }) {
    if (member.isCurrentUser) {
        return (
            <tr>
                <td className="px-5 py-4 text-sm whitespace-nowrap">{member.name}</td>
                <td className="px-5 py-4 text-sm whitespace-nowrap">{member.email}</td>
                <td className="px-5 py-4 text-sm whitespace-nowrap">{member.role}</td>
                <td className="px-5 py-4 text-sm whitespace-nowrap">(This is you)</td>
            </tr>
        );
    }

    const actions = !canManageMembers
        ? []
        : currentUserRole === 'owner'
          ? roleActionsForOwnerViewer(member)
          : currentUserRole === 'admin'
            ? roleActionsForAdminViewer(member)
            : [];

    function updateRole(role) {
        router.put(member.updateRoleUrl, { role }, { preserveScroll: true });
    }

    function remove() {
        if (!window.confirm(`Remove ${member.name} from this team?`)) return;
        router.delete(member.removeUrl, { preserveScroll: true });
    }

    return (
        <tr>
            <td className="px-5 py-4 text-sm whitespace-nowrap">{member.name}</td>
            <td className="px-5 py-4 text-sm whitespace-nowrap">{member.email}</td>
            <td className="px-5 py-4 text-sm whitespace-nowrap">{member.role}</td>
            <td className="flex gap-2 px-5 py-4 text-sm whitespace-nowrap">
                {actions.map((action) => (
                    <button key={action.role} type="button" onClick={() => updateRole(action.role)}>
                        {action.label}
                    </button>
                ))}
                {actions.length > 0 && (
                    <button type="button" onClick={remove}>
                        Remove
                    </button>
                )}
            </td>
        </tr>
    );
}

function InvitationRow({ invitation }) {
    const [showLink, setShowLink] = useState(false);

    function revoke() {
        if (!window.confirm('Revoke this invitation?')) return;
        router.delete(invitation.deleteUrl, { preserveScroll: true });
    }

    function copyLink() {
        navigator.clipboard?.writeText(invitation.link);
    }

    return (
        <tr>
            <td className="px-5 py-4 text-sm whitespace-nowrap">{invitation.email}</td>
            <td className="px-5 py-4 text-sm whitespace-nowrap">{invitation.via}</td>
            <td className="px-5 py-4 text-sm whitespace-nowrap">{invitation.role}</td>
            <td className="px-5 py-4 text-sm whitespace-nowrap">
                <div className="flex gap-2">
                    <input
                        id={`invitation-${invitation.id}-link`}
                        name={`invitation-${invitation.id}-link`}
                        type={showLink ? 'text' : 'password'}
                        readOnly
                        value={invitation.link}
                    />
                    <button type="button" onClick={() => setShowLink((v) => !v)}>
                        {showLink ? 'Hide' : 'Show'}
                    </button>
                    <button type="button" onClick={copyLink}>
                        Copy Invitation Link
                    </button>
                </div>
            </td>
            <td className="px-5 py-4 text-sm whitespace-nowrap">
                <button type="button" onClick={revoke}>
                    Revoke Invitation
                </button>
            </td>
        </tr>
    );
}

export default function Index({
    members,
    currentUserRole,
    canManageMembers,
    canManageInvitations,
    isInstanceAdmin,
    isTransactionalEmailsEnabled,
    invitations,
    sendInvitationUrl,
}) {
    const { permissions } = usePage().props;
    const { data, setData, processing, errors, reset } = useForm({ email: '', role: 'member' });

    function submitInvite(e) {
        e.preventDefault();
        // useForm's own post(url, options) doesn't accept a data override in options - only
        // router.post(url, data, options) does. This used to silently submit without `via` at
        // all (options.data was ignored), failing the backend's required `via` validation on
        // every click with zero visible feedback, since nothing here renders errors.via.
        router.post(sendInvitationUrl, { ...data, via: 'link' }, { preserveScroll: true, onSuccess: () => reset() });
    }

    function sendViaEmail() {
        router.post(sendInvitationUrl, { ...data, via: 'email' }, { preserveScroll: true, onSuccess: () => reset() });
    }

    return (
        <div>
            <div className="pb-6">
                <div className="flex items-end gap-2">
                    <h1>Team</h1>
                </div>
                <div className="subtitle">Team wide configurations.</div>
                <div className="navbar-main">
                    <nav className="flex items-center gap-6 min-h-10">
                        <a href="/team">General</a>
                        <a href="/team/members" className="dark:text-white">
                            Members
                        </a>
                        {(permissions?.isInstanceAdmin ?? isInstanceAdmin) && <a href="/team/admin">Admin View</a>}
                        <div className="flex-1" />
                    </nav>
                </div>
            </div>

            <h2>Members</h2>
            <div className="subtitle">Manage or invite members of this team.</div>
            <div className="overflow-x-auto">
                <div className="inline-block min-w-full">
                    <div className="overflow-hidden">
                        <table className="min-w-full">
                            <thead>
                                <tr>
                                    <th className="px-5 py-3 text-xs font-medium text-left uppercase">Name</th>
                                    <th className="px-5 py-3 text-xs font-medium text-left uppercase">Email</th>
                                    <th className="px-5 py-3 text-xs font-medium text-left uppercase">Role</th>
                                    <th className="px-5 py-3 text-xs font-medium text-left uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {members.map((member) => (
                                    <MemberRow
                                        key={member.id}
                                        member={member}
                                        currentUserRole={currentUserRole}
                                        canManageMembers={canManageMembers}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {canManageInvitations && (
                <div className="py-4">
                    <h2 className="pb-4">Invite New Member</h2>
                    {!isTransactionalEmailsEnabled && isInstanceAdmin && (
                        <div className="pb-4 text-xs dark:text-warning">
                            You need to configure (as root team){' '}
                            <a href="/settings/email" className="underline dark:text-warning">
                                Transactional Emails
                            </a>{' '}
                            before you can invite a new member via email.
                        </div>
                    )}
                    <form onSubmit={submitInvite} className="flex gap-2 flex-col lg:flex-row items-end">
                        <div className="flex flex-1 lg:w-fit w-full gap-2">
                            <label className="flex flex-col gap-1 w-full">
                                Email
                                <input
                                    id="team-invite-email"
                                    name="team-invite-email"
                                    type="email"
                                    required
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                />
                                {errors.email && <span className="text-error">{errors.email}</span>}
                            </label>
                            <label className="flex flex-col gap-1">
                                Role
                                <select
                                    id="team-invite-role"
                                    name="team-invite-role"
                                    value={data.role}
                                    onChange={(e) => setData('role', e.target.value)}
                                >
                                    {currentUserRole === 'owner' && <option value="owner">Owner</option>}
                                    <option value="admin">Admin</option>
                                    <option value="member">Member</option>
                                </select>
                            </label>
                        </div>
                        <div className="flex gap-2 lg:w-fit w-full">
                            <button type="submit" disabled={processing}>
                                Generate Invitation Link
                            </button>
                            {isTransactionalEmailsEnabled && (
                                <button type="button" onClick={sendViaEmail} disabled={processing}>
                                    Send Invitation via Email
                                </button>
                            )}
                        </div>
                    </form>
                </div>
            )}

            {canManageInvitations && invitations.length > 0 && (
                <div>
                    <h2 className="pb-2">Pending Invitations</h2>
                    <div className="overflow-x-auto">
                        <div className="inline-block min-w-full">
                            <div className="overflow-hidden">
                                <table className="min-w-full">
                                    <thead>
                                        <tr>
                                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Email</th>
                                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Via</th>
                                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Role</th>
                                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Invitation Link</th>
                                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {invitations.map((invitation) => (
                                            <InvitationRow key={invitation.id} invitation={invitation} />
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
