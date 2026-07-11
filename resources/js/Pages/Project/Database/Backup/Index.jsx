import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConfigurationChecker from '../../../../Components/ConfigurationChecker';
import DatabaseHeading from '../../../../Components/DatabaseHeading';

const STATUS_LABELS = {
    running: 'In Progress',
    failed: 'Failed',
    success: 'Success',
};

const STATUS_BORDER = {
    running: 'border-blue-500/50 border-dashed',
    failed: 'border-error',
    success: 'border-success',
};

const STATUS_BADGE = {
    running: 'bg-blue-100/80 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200',
    success: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200',
};

function CreateBackupModal({ open, onClose, storeUrl, s3Storages }) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        frequency: '',
        save_to_s3: false,
        s3_storage_id: s3Storages[0]?.id ?? null,
    });

    function handleClose() {
        reset();
        clearErrors();
        onClose();
    }

    function submit(e) {
        e.preventDefault();
        post(storeUrl, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                clearErrors();
                onClose();
            },
        });
    }

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={handleClose} />
            <div className="relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                <div className="flex items-center justify-between pb-4">
                    <h3 className="text-2xl font-bold">New Scheduled Backup</h3>
                    <button type="button" onClick={handleClose}>
                        ✕
                    </button>
                </div>
                <form className="flex flex-col gap-2" onSubmit={submit}>
                    <label className="flex flex-col gap-1">
                        Frequency
                        <input
                            placeholder="e.g. 0 0 * * * or 'every night'"
                            required
                            value={data.frequency}
                            onChange={(e) => setData('frequency', e.target.value)}
                        />
                        {errors.frequency && <span className="text-error">{errors.frequency}</span>}
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={data.save_to_s3}
                            onChange={(e) => setData('save_to_s3', e.target.checked)}
                        />
                        Save to S3?
                    </label>
                    {data.save_to_s3 && (
                        <label className="flex flex-col gap-1">
                            S3 Storage
                            <select value={data.s3_storage_id ?? ''} onChange={(e) => setData('s3_storage_id', e.target.value ? Number(e.target.value) : null)}>
                                <option value="">Choose an S3 storage...</option>
                                {s3Storages.map((s3) => (
                                    <option key={s3.id} value={s3.id}>
                                        {s3.name}
                                    </option>
                                ))}
                            </select>
                            {errors.s3_storage_id && <span className="text-error">{errors.s3_storage_id}</span>}
                        </label>
                    )}
                    <button type="submit" disabled={processing}>
                        Save
                    </button>
                </form>
            </div>
        </div>
    );
}

function BackupCard({ backup }) {
    return (
        <a
            className={`flex flex-col border-l-2 transition-colors p-4 cursor-pointer bg-white hover:bg-gray-100 dark:bg-coolgray-100 dark:hover:bg-coolgray-200 text-black dark:text-white ${
                backup.status ? (STATUS_BORDER[backup.status] ?? 'border-gray-200 dark:border-coolgray-300') : 'border-gray-200 dark:border-coolgray-300'
            }`}
            href={backup.executeUrl}
        >
            <div className="flex items-center gap-2 mb-2">
                {backup.status ? (
                    <span className={`px-3 py-1 rounded-md text-xs font-medium tracking-wide shadow-xs ${STATUS_BADGE[backup.status] ?? ''}`}>
                        {STATUS_LABELS[backup.status] ?? backup.status}
                    </span>
                ) : (
                    <span className="px-3 py-1 rounded-md text-xs font-medium tracking-wide shadow-xs bg-gray-100 text-gray-800 dark:bg-neutral-800 dark:text-gray-200">
                        No executions yet
                    </span>
                )}
                <h3 className="font-semibold">{backup.frequency}</h3>
            </div>
            <div className="text-gray-600 dark:text-gray-400 text-sm">
                {backup.timingText ? (
                    <>
                        {backup.timingText}
                        {backup.sizeText && <> • Size: {backup.sizeText}</>}
                        {backup.saveS3 && <> • S3: Enabled</>}
                    </>
                ) : (
                    <>
                        Last Run: Never • Total Executions: 0
                        {backup.saveS3 && <> • S3: Enabled</>}
                    </>
                )}
            </div>
        </a>
    );
}

export default function Index({ heading, configurationChecker, scheduledBackups, s3Storages, canUpdate, urls }) {
    const [showAddModal, setShowAddModal] = useState(false);

    return (
        <div>
            <h1>Backups</h1>
            <ConfigurationChecker configurationChecker={configurationChecker} />
            <DatabaseHeading heading={heading} urls={urls} />

            <div>
                <div className="flex gap-2">
                    <h2 className="pb-4">Scheduled Backups</h2>
                    {canUpdate && (
                        <button type="button" onClick={() => setShowAddModal(true)}>
                            + Add
                        </button>
                    )}
                </div>

                <div className="flex flex-col gap-2">
                    {scheduledBackups.length === 0 && <div>No scheduled backups configured.</div>}
                    {scheduledBackups.map((backup) => (
                        <BackupCard key={backup.id} backup={backup} />
                    ))}
                </div>
            </div>

            <CreateBackupModal open={showAddModal} onClose={() => setShowAddModal(false)} storeUrl={urls.store} s3Storages={s3Storages} />
        </div>
    );
}
