import { usePage } from '@inertiajs/react';

const EVENT_LABELS = {
    created: 'Created',
    updated: 'Updated',
    deleted: 'Deleted',
    'member.invited': 'Member Invited',
    'member.joined': 'Member Joined',
    'member.role_updated': 'Role Changed',
    'member.removed': 'Member Removed',
};

function formatEvent(event) {
    return EVENT_LABELS[event] ?? event ?? '—';
}

function ChangeList({ changes }) {
    const attributes = changes?.attributes;
    const old = changes?.old;

    if (!attributes || Object.keys(attributes).length === 0) {
        return null;
    }

    return (
        <ul className="flex flex-col gap-0.5 text-xs text-neutral-500 dark:text-coolgray-400">
            {Object.entries(attributes).map(([field, newValue]) => (
                <li key={field}>
                    <span className="font-medium">{field}</span>:{' '}
                    {old && field in old ? (
                        <>
                            <span className="line-through">{String(old[field])}</span> → {String(newValue)}
                        </>
                    ) : (
                        String(newValue)
                    )}
                </li>
            ))}
        </ul>
    );
}

export default function AuditLog({ entries }) {
    const { permissions } = usePage().props;

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
                        <a href="/team/members">Members</a>
                        {permissions?.isInstanceAdmin && <a href="/team/admin">Admin View</a>}
                        <a href="/team/audit-log" className="dark:text-white">
                            Audit Log
                        </a>
                        <div className="flex-1" />
                    </nav>
                </div>
            </div>

            <h2>Audit Log</h2>
            <div className="subtitle">Who changed what, for this team&apos;s resources and membership. Showing the most recent 100 entries.</div>

            {entries.length === 0 ? (
                <div className="text-sm text-neutral-500 dark:text-coolgray-400">No audit log entries yet.</div>
            ) : (
                <div className="overflow-x-auto">
                    <div className="inline-block min-w-full">
                        <div className="overflow-hidden">
                            <table className="min-w-full">
                                <thead>
                                    <tr>
                                        <th className="px-5 py-2 text-left text-xs font-medium uppercase">When</th>
                                        <th className="px-5 py-2 text-left text-xs font-medium uppercase">Who</th>
                                        <th className="px-5 py-2 text-left text-xs font-medium uppercase">Event</th>
                                        <th className="px-5 py-2 text-left text-xs font-medium uppercase">Subject</th>
                                        <th className="px-5 py-2 text-left text-xs font-medium uppercase">Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {entries.map((entry) => (
                                        <tr key={entry.id}>
                                            <td className="px-5 py-4 text-sm whitespace-nowrap">
                                                {entry.createdAt ? new Date(entry.createdAt).toLocaleString() : '—'}
                                            </td>
                                            <td className="px-5 py-4 text-sm whitespace-nowrap">{entry.causerName}</td>
                                            <td className="px-5 py-4 text-sm whitespace-nowrap">{formatEvent(entry.event)}</td>
                                            <td className="px-5 py-4 text-sm whitespace-nowrap">{entry.subjectType ?? '—'}</td>
                                            <td className="px-5 py-4 text-sm">
                                                <div>{entry.description}</div>
                                                <ChangeList changes={entry.changes} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
