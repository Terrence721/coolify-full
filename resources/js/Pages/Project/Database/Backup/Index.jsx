import { useState } from 'react';
import ConfigurationChecker from '../../../../Components/ConfigurationChecker';
import CreateBackupModal from '../../../../Components/CreateBackupModal';
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

function BackupCard({ backup }) {
    return (
        <a
            className={`flex flex-col border-l-2 transition-colors p-4 cursor-pointer bg-white hover:bg-gray-100 dark:bg-coolgray-100 dark:hover:bg-coolgray-200 text-black dark:text-white ${
                backup.status
                    ? (STATUS_BORDER[backup.status] ?? 'border-gray-200 dark:border-coolgray-300')
                    : 'border-gray-200 dark:border-coolgray-300'
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
                    <>Last Run: Never • Total Executions: 0{backup.saveS3 && <> • S3: Enabled</>}</>
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
