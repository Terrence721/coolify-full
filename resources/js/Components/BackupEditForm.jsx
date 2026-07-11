import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PasswordConfirmModal from './PasswordConfirmModal';

export default function BackupEditForm({ backup, s3Storages, urls }) {
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
