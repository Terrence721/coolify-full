import { router } from '@inertiajs/react';
import { useState } from 'react';

export default function Resources({ storage, backups, allStorages, canUpdate, showUrl, resourcesUrl }) {
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState(() => Object.fromEntries(backups.map((b) => [b.id, storage.id])));

    const filtered = backups.filter(
        (backup) =>
            search === '' ||
            backup.databaseName.toLowerCase().includes(search.toLowerCase()) ||
            backup.frequency.toLowerCase().includes(search.toLowerCase()),
    );

    function moveBackup(backup) {
        const newStorageId = selected[backup.id];
        if (!newStorageId) return;
        router.post(backup.moveBackupUrl, { new_storage_id: newStorageId }, { preserveScroll: true });
    }

    function disableS3(backup) {
        if (!window.confirm('Are you sure you want to disable S3 for this backup schedule?')) return;
        router.post(backup.disableS3Url, {}, { preserveScroll: true });
    }

    return (
        <div>
            <div className="flex items-center gap-2">
                <h1>Storage Details</h1>
                {storage.isUsable ? (
                    <span className="px-2 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded dark:text-green-100 dark:bg-green-800">
                        Usable
                    </span>
                ) : (
                    <span className="px-2 py-1 text-xs font-semibold text-red-800 bg-red-100 rounded dark:text-red-100 dark:bg-red-800">
                        Not Usable
                    </span>
                )}
            </div>
            <div className="subtitle">{storage.name}</div>

            <div className="navbar-main">
                <nav className="flex shrink-0 gap-6 items-center whitespace-nowrap scrollbar min-h-10">
                    <a href={showUrl}>General</a>
                    <a className="dark:text-white" href={resourcesUrl}>
                        Resources
                    </a>
                </nav>
            </div>

            <div className="pt-4">
                <input
                    id="storage-resources-search"
                    name="storage-resources-search"
                    placeholder="Search resources..."
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                />
                {filtered.length > 0 ? (
                    <div className="overflow-x-auto pt-4">
                        <div className="inline-block min-w-full">
                            <div className="overflow-hidden">
                                <table className="min-w-full">
                                    <thead>
                                        <tr>
                                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Database</th>
                                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Frequency</th>
                                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Status</th>
                                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">S3 Storage</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {filtered.map((backup) => (
                                            <tr key={backup.id} className="dark:hover:bg-coolgray-300 hover:bg-neutral-100">
                                                <td className="px-5 py-4 text-sm whitespace-nowrap">
                                                    {backup.resourceLink ? (
                                                        <a className="hover:underline" href={backup.resourceLink}>
                                                            {backup.databaseName}
                                                        </a>
                                                    ) : (
                                                        backup.databaseName
                                                    )}
                                                </td>
                                                <td className="px-5 py-4 text-sm whitespace-nowrap">
                                                    {backup.backupLink ? (
                                                        <a className="hover:underline" href={backup.backupLink}>
                                                            {backup.frequency}
                                                        </a>
                                                    ) : (
                                                        backup.frequency
                                                    )}
                                                </td>
                                                <td className="px-5 py-4 text-sm font-medium whitespace-nowrap">
                                                    {backup.enabled ? (
                                                        <span className="text-green-500">Enabled</span>
                                                    ) : (
                                                        <span className="text-yellow-500">Disabled</span>
                                                    )}
                                                </td>
                                                <td className="px-5 py-4 text-sm whitespace-nowrap">
                                                    <div className="flex items-center gap-2">
                                                        <select
                                                            id={`storage-resources-backup-${backup.id}`}
                                                            name={`storage-resources-backup-${backup.id}`}
                                                            className="w-full input"
                                                            disabled={!canUpdate}
                                                            value={selected[backup.id] ?? ''}
                                                            onChange={(e) => setSelected((prev) => ({ ...prev, [backup.id]: e.target.value }))}
                                                        >
                                                            {allStorages.map((s3) => (
                                                                <option key={s3.id} value={s3.id} disabled={!s3.isUsable}>
                                                                    {s3.name}
                                                                    {!s3.isUsable ? ' (unusable)' : ''}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        {canUpdate && (
                                                            <>
                                                                <button type="button" onClick={() => moveBackup(backup)}>
                                                                    Save
                                                                </button>
                                                                <button type="button" onClick={() => disableS3(backup)}>
                                                                    Disable S3
                                                                </button>
                                                            </>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="pt-4">No backup schedules are using this storage.</div>
                )}
            </div>
        </div>
    );
}
