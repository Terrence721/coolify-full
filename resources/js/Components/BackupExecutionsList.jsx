import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ExecutionCard from './ExecutionCard';
import PasswordConfirmModal from './PasswordConfirmModal';
import { useTeamChannel } from '../hooks/useTeamChannel';

export default function BackupExecutionsList({ executions, executionsCount, skip, defaultTake, currentPage, showNext, showPrev, urls }) {
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
        const params = new URLSearchParams(window.location.search);
        params.set('skip', newSkip);
        router.get(`${window.location.pathname}?${params.toString()}`, {}, { preserveState: true, preserveScroll: true });
    }

    function cleanupFailed() {
        router.post(urls.cleanupFailed, {}, { preserveScroll: true });
    }

    return (
        <div>
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
