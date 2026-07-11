import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConfigurationChecker from '../../../../Components/ConfigurationChecker';
import DatabaseHeading from '../../../../Components/DatabaseHeading';
import { useTeamChannel } from '../../../../hooks/useTeamChannel';

const STATUS_BORDER = {
    running: 'border-blue-500/50 border-dashed',
    failed: 'border-error',
    success: 'border-success',
};

function PasswordConfirmModal({ title, action, actions, checkboxes = [], confirmationText, confirmationLabel, onClose, onDone }) {
    const [selectedActions, setSelectedActions] = useState([]);
    const [confirmation, setConfirmation] = useState('');
    const [password, setPassword] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState(null);

    function toggleAction(id) {
        setSelectedActions((prev) => (prev.includes(id) ? prev.filter((a) => a !== id) : [...prev, id]));
    }

    function submit(e) {
        e.preventDefault();
        if (confirmationText && confirmation !== confirmationText) return;
        setProcessing(true);
        setError(null);
        const data = { password };
        selectedActions.forEach((id) => {
            data[id] = true;
        });
        const callbacks = {
            preserveScroll: true,
            onSuccess: () => onDone?.(),
            onError: (errors) => setError(errors.password ?? 'Something went wrong.'),
            onFinish: () => setProcessing(false),
        };
        if (action.method === 'delete') {
            router.delete(action.url, { ...callbacks, data });
        } else {
            router[action.method](action.url, data, callbacks);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={onClose} />
            <div className="relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                <div className="flex shrink-0 items-center justify-between border-b border-neutral-200 px-6 py-5 dark:border-coolgray-300">
                    <h3 className="text-2xl font-bold">{title}</h3>
                    <button type="button" onClick={onClose}>
                        ✕
                    </button>
                </div>
                <form onSubmit={submit} className="flex flex-col gap-3 p-6">
                    <ul className="list-disc pl-4 text-sm">
                        {actions.map((a) => (
                            <li key={a}>{a}</li>
                        ))}
                    </ul>
                    {checkboxes.map((cb) => (
                        <label key={cb.id} className="flex items-center gap-2">
                            <input type="checkbox" checked={selectedActions.includes(cb.id)} onChange={() => toggleAction(cb.id)} />
                            {cb.label}
                        </label>
                    ))}
                    {confirmationText && (
                        <label className="flex flex-col gap-1">
                            {confirmationLabel}
                            <input value={confirmation} onChange={(e) => setConfirmation(e.target.value)} placeholder={confirmationText} />
                        </label>
                    )}
                    <label className="flex flex-col gap-1">
                        Password
                        <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} />
                    </label>
                    {error && <span className="text-error">{error}</span>}
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={onClose}>
                            Cancel
                        </button>
                        <button
                            type="submit"
                            className="text-error"
                            disabled={processing || !password || (confirmationText && confirmation !== confirmationText)}
                        >
                            Confirm
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function BackupEditForm({ backup, s3Storages, urls }) {
    const [showDeleteModal, setShowDeleteModal] = useState(false);
    const { data, setData, put, processing, errors } = useForm({
        enabled: backup.enabled,
        frequency: backup.frequency,
        timeout: backup.timeout,
        save_s3: backup.saveS3,
        disable_local_backup: backup.disableLocalBackup,
        s3_storage_id: backup.s3StorageId,
        databases_to_backup: backup.databasesToBackup ?? '',
        dump_all: backup.dumpAll,
        database_backup_retention_amount_locally: backup.databaseBackupRetentionAmountLocally,
        database_backup_retention_days_locally: backup.databaseBackupRetentionDaysLocally,
        database_backup_retention_max_storage_locally: backup.databaseBackupRetentionMaxStorageLocally,
        database_backup_retention_amount_s3: backup.databaseBackupRetentionAmountS3,
        database_backup_retention_days_s3: backup.databaseBackupRetentionDaysS3,
        database_backup_retention_max_storage_s3: backup.databaseBackupRetentionMaxStorageS3,
    });

    function submit(e) {
        e.preventDefault();
        put(urls.update, { preserveScroll: true });
    }

    function backupNow() {
        router.post(urls.backupNow, {}, { preserveScroll: true });
    }

    const isMysqlFamily = ['App\\Models\\StandalonePostgresql', 'App\\Models\\StandaloneMysql', 'App\\Models\\StandaloneMariadb'].includes(backup.databaseType);
    const isMongo = backup.databaseType === 'App\\Models\\StandaloneMongodb';

    return (
        <form onSubmit={submit} className="flex flex-col gap-2">
            <div className="flex gap-2 pb-2 items-center">
                <h2>Scheduled Backup</h2>
                <button type="submit" disabled={processing}>
                    Save
                </button>
                {backup.status?.startsWith('running') && (
                    <button type="button" onClick={backupNow}>
                        Backup Now
                    </button>
                )}
                {backup.databaseId !== 0 && (
                    <button type="button" className="text-error" onClick={() => setShowDeleteModal(true)}>
                        Delete Backups and Schedule
                    </button>
                )}
            </div>

            <div className="w-64 pb-2 flex flex-col gap-1">
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={data.enabled} onChange={(e) => setData('enabled', e.target.checked)} />
                    Backup Enabled
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        checked={data.save_s3}
                        disabled={s3Storages.length === 0}
                        onChange={(e) => setData('save_s3', e.target.checked)}
                    />
                    S3 Enabled
                    {s3Storages.length === 0 && <span className="text-xs opacity-70">(No validated S3 storage available.)</span>}
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        checked={data.disable_local_backup}
                        disabled={!data.save_s3}
                        onChange={(e) => setData('disable_local_backup', e.target.checked)}
                    />
                    Disable Local Backup
                </label>
            </div>

            {data.save_s3 && (
                <label className="flex flex-col gap-1 pb-4">
                    S3 Storage
                    <select value={data.s3_storage_id ?? ''} onChange={(e) => setData('s3_storage_id', e.target.value ? Number(e.target.value) : null)}>
                        <option value="">Select a S3 storage</option>
                        {s3Storages.map((s3) => (
                            <option key={s3.id} value={s3.id}>
                                {s3.name}
                            </option>
                        ))}
                    </select>
                    {errors.s3_storage_id && <span className="text-error">{errors.s3_storage_id}</span>}
                </label>
            )}

            <h3>Settings</h3>
            <div className="flex flex-col gap-2">
                {isMysqlFamily && (
                    <>
                        <label className="flex items-center gap-2 w-48">
                            <input type="checkbox" checked={data.dump_all} onChange={(e) => setData('dump_all', e.target.checked)} />
                            Backup All Databases
                        </label>
                        {!data.dump_all && (
                            <label className="flex flex-col gap-1">
                                Databases To Backup
                                <input
                                    value={data.databases_to_backup}
                                    onChange={(e) => setData('databases_to_backup', e.target.value)}
                                    placeholder="Comma separated list of databases to backup. Empty will include the default one."
                                />
                            </label>
                        )}
                    </>
                )}
                {isMongo && (
                    <label className="flex flex-col gap-1">
                        Databases To Include
                        <input value={data.databases_to_backup} onChange={(e) => setData('databases_to_backup', e.target.value)} />
                    </label>
                )}
                {errors.databases_to_backup && <span className="text-error">{errors.databases_to_backup}</span>}
            </div>

            <div className="flex gap-2">
                <label className="flex flex-col gap-1">
                    Frequency
                    <input required value={data.frequency} onChange={(e) => setData('frequency', e.target.value)} />
                    {errors.frequency && <span className="text-error">{errors.frequency}</span>}
                </label>
                <label className="flex flex-col gap-1">
                    Timezone
                    <input disabled value={backup.timezone} title="The timezone of the server where the backup is scheduled to run" />
                </label>
                <label className="flex flex-col gap-1">
                    Timeout
                    <input
                        type="number"
                        min={60}
                        required
                        value={data.timeout}
                        onChange={(e) => setData('timeout', Number(e.target.value))}
                    />
                </label>
            </div>

            <h3 className="mt-6 mb-2 text-lg font-medium">Backup Retention Settings</h3>
            <ul className="list-disc pl-6 space-y-2 text-sm mb-4">
                <li>Setting a value to 0 means unlimited retention.</li>
                <li>The retention rules work independently - whichever limit is reached first will trigger cleanup.</li>
            </ul>

            <div className="flex gap-6 flex-col">
                <div>
                    <h4 className="mb-3 font-medium">Local Backup Retention</h4>
                    <div className="flex gap-2">
                        <label className="flex flex-col gap-1">
                            Number of backups to keep
                            <input
                                type="number"
                                min={0}
                                value={data.database_backup_retention_amount_locally}
                                onChange={(e) => setData('database_backup_retention_amount_locally', Number(e.target.value))}
                            />
                        </label>
                        <label className="flex flex-col gap-1">
                            Days to keep backups
                            <input
                                type="number"
                                min={0}
                                value={data.database_backup_retention_days_locally}
                                onChange={(e) => setData('database_backup_retention_days_locally', Number(e.target.value))}
                            />
                        </label>
                        <label className="flex flex-col gap-1">
                            Maximum storage (GB)
                            <input
                                type="number"
                                min={0}
                                step="any"
                                value={data.database_backup_retention_max_storage_locally}
                                onChange={(e) => setData('database_backup_retention_max_storage_locally', Number(e.target.value))}
                            />
                        </label>
                    </div>
                </div>

                {data.save_s3 && (
                    <div>
                        <h4 className="mb-3 font-medium">S3 Storage Retention</h4>
                        <div className="flex gap-2">
                            <label className="flex flex-col gap-1">
                                Number of backups to keep
                                <input
                                    type="number"
                                    min={0}
                                    value={data.database_backup_retention_amount_s3}
                                    onChange={(e) => setData('database_backup_retention_amount_s3', Number(e.target.value))}
                                />
                            </label>
                            <label className="flex flex-col gap-1">
                                Days to keep backups
                                <input
                                    type="number"
                                    min={0}
                                    value={data.database_backup_retention_days_s3}
                                    onChange={(e) => setData('database_backup_retention_days_s3', Number(e.target.value))}
                                />
                            </label>
                            <label className="flex flex-col gap-1">
                                Maximum storage (GB)
                                <input
                                    type="number"
                                    min={0}
                                    step="any"
                                    value={data.database_backup_retention_max_storage_s3}
                                    onChange={(e) => setData('database_backup_retention_max_storage_s3', Number(e.target.value))}
                                />
                            </label>
                        </div>
                    </div>
                )}
            </div>

            {showDeleteModal && (
                <PasswordConfirmModal
                    title="Confirm Backup Schedule Deletion?"
                    action={{ method: 'delete', url: urls.destroy }}
                    actions={[
                        'The selected backup schedule will be deleted.',
                        'Scheduled backups for this database will be stopped (if this is the only backup schedule for this database).',
                    ]}
                    checkboxes={[
                        { id: 'delete_associated_backups_locally', label: 'All backups will be permanently deleted from local storage.' },
                        { id: 'delete_associated_backups_s3', label: 'All backups will be permanently deleted (associated with this backup job) from the selected S3 Storage.' },
                    ]}
                    confirmationText={backup.databaseName}
                    confirmationLabel="Please confirm the execution of the actions by entering the Database Name of the scheduled backups below"
                    onClose={() => setShowDeleteModal(false)}
                />
            )}
        </form>
    );
}

function ExecutionCard({ execution }) {
    const [showDeleteModal, setShowDeleteModal] = useState(false);

    const statusText =
        execution.status === 'success'
            ? execution.s3Uploaded === false
                ? 'Success (S3 Warning)'
                : 'Success'
            : execution.status === 'running'
              ? 'In Progress'
              : execution.status === 'failed'
                ? 'Failed'
                : execution.status;

    return (
        <div className={`relative flex flex-col border-l-2 transition-colors p-4 bg-white dark:bg-coolgray-100 text-black dark:text-white ${STATUS_BORDER[execution.status] ?? ''}`}>
            <div className="flex items-center gap-2 mb-2">
                <span className="px-3 py-1 rounded-md text-xs font-medium tracking-wide shadow-xs bg-gray-100 text-gray-800 dark:bg-neutral-800 dark:text-gray-200">
                    {statusText}
                </span>
            </div>
            <div className="text-gray-600 dark:text-gray-400 text-sm">
                {execution.timingText}
                {' • '}Database: {execution.databaseName ?? 'N/A'}
                {execution.size && <> • Size: {execution.size}</>}
            </div>
            <div className="text-gray-600 dark:text-gray-400 text-sm">Location: {execution.filename ?? 'N/A'}</div>
            <div className="flex items-center gap-3 mt-2 text-sm">
                <span>Backup Availability:</span>
                <span className="px-2 py-1 rounded-sm text-xs font-medium bg-gray-100 dark:bg-gray-800/50">
                    {execution.localStorageDeleted ? 'Local Storage: deleted' : 'Local Storage: available'}
                </span>
            </div>
            {execution.message && (
                <div className="mt-2 p-2 bg-gray-100 dark:bg-coolgray-200 rounded-sm">
                    <pre className="whitespace-pre-wrap text-sm">{execution.message}</pre>
                </div>
            )}
            <div className="flex gap-2 mt-4">
                {execution.status === 'success' && (
                    <a className="dark:hover:bg-coolgray-400" href={execution.downloadUrl} target="_blank" rel="noreferrer">
                        Download
                    </a>
                )}
                <button type="button" className="text-error" onClick={() => setShowDeleteModal(true)}>
                    Delete
                </button>
            </div>

            {showDeleteModal && (
                <PasswordConfirmModal
                    title="Confirm Backup Deletion?"
                    action={{ method: 'delete', url: execution.destroyUrl }}
                    actions={
                        execution.localStorageDeleted
                            ? ['This backup execution record will be deleted.']
                            : ['This backup will be permanently deleted from local storage.']
                    }
                    checkboxes={
                        execution.s3Uploaded && !execution.s3StorageDeleted
                            ? [{ id: 'delete_backup_s3', label: 'Delete the selected backup permanently from S3 Storage' }]
                            : []
                    }
                    confirmationText={execution.filename}
                    confirmationLabel="Please confirm the execution of the actions by entering the Backup Filename below"
                    onClose={() => setShowDeleteModal(false)}
                    onDone={() => setShowDeleteModal(false)}
                />
            )}
        </div>
    );
}

export default function Execution({
    heading,
    configurationChecker,
    backup,
    s3Storages,
    executions,
    executionsCount,
    skip,
    defaultTake,
    currentPage,
    showNext,
    showPrev,
    urls,
}) {
    const [showCleanupDeletedModal, setShowCleanupDeletedModal] = useState(false);
    const pollRef = useRef(null);

    useTeamChannel(['BackupCreated'], () => {
        router.reload({ only: ['executions', 'executionsCount', 'showNext', 'showPrev'] });
    });

    // Mirrors the original's wire:poll.5000ms="refreshBackupExecutions" (only active on the
    // first page, matching `@if (!$skip)`).
    useEffect(() => {
        if (skip) return;
        pollRef.current = setInterval(() => {
            router.reload({ only: ['executions', 'executionsCount', 'showNext', 'showPrev'], preserveScroll: true });
        }, 5000);

        return () => clearInterval(pollRef.current);
    }, [skip]);

    function reload(newSkip) {
        router.get(window.location.pathname, { skip: newSkip }, { preserveState: true, preserveScroll: true });
    }

    function cleanupFailed() {
        router.post(urls.cleanupFailed, {}, { preserveScroll: true });
    }

    return (
        <div>
            <h1>Backups</h1>
            <ConfigurationChecker configurationChecker={configurationChecker} />
            <DatabaseHeading heading={heading} urls={urls} />

            <BackupEditForm backup={backup} s3Storages={s3Storages} urls={urls} />

            <div className="flex items-center gap-2">
                <h3 className="py-4">
                    Executions <span className="text-xs">({executionsCount})</span>
                </h3>
                {executionsCount > 0 && (
                    <div className="flex items-center gap-2">
                        <button type="button" disabled={!showPrev} onClick={() => reload(Math.max(0, skip - defaultTake))}>
                            ←
                        </button>
                        <span className="text-sm opacity-70 px-2">
                            Page {currentPage} of {Math.ceil(executionsCount / defaultTake)}
                        </span>
                        <button type="button" disabled={!showNext} onClick={() => reload(skip + defaultTake)}>
                            →
                        </button>
                    </div>
                )}
                <button type="button" onClick={cleanupFailed}>
                    Cleanup Failed Backups
                </button>
                <button type="button" className="text-error" onClick={() => setShowCleanupDeletedModal(true)}>
                    Cleanup Deleted
                </button>
            </div>

            <div className="flex flex-col gap-4">
                {executions.length === 0 && <div className="p-4 bg-gray-100 dark:bg-coolgray-100 rounded-sm">No executions found.</div>}
                {executions.map((execution) => (
                    <ExecutionCard key={execution.id} execution={execution} />
                ))}
            </div>

            {showCleanupDeletedModal && (
                <PasswordConfirmModal
                    title="Cleanup Deleted Backup Entries?"
                    action={{ method: 'post', url: urls.cleanupDeleted }}
                    actions={[
                        'This will permanently delete all backup execution entries that are marked as deleted from local storage.',
                        'This only removes database entries, not actual backup files.',
                    ]}
                    confirmationText="cleanup deleted backups"
                    confirmationLabel="Please confirm by typing 'cleanup deleted backups' below"
                    onClose={() => setShowCleanupDeletedModal(false)}
                    onDone={() => setShowCleanupDeletedModal(false)}
                />
            )}
        </div>
    );
}
