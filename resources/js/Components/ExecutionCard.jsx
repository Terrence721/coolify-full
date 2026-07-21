import { useState } from 'react';
import PasswordConfirmModal from './PasswordConfirmModal';

const STATUS_BORDER = {
    running: 'border-blue-500/50 border-dashed',
    failed: 'border-error',
    success: 'border-success',
};

export default function ExecutionCard({ execution }) {
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
        <div
            className={`relative flex flex-col border-l-2 transition-colors p-4 bg-white dark:bg-coolgray-100 text-black dark:text-white ${STATUS_BORDER[execution.status] ?? ''}`}
        >
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
