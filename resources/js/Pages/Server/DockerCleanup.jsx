import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ServerNavbar from '../../Components/ServerNavbar';
import ServerSidebar from '../../Components/ServerSidebar';
import { useTeamChannel } from '../../hooks/useTeamChannel';

const LOGS_PER_PAGE = 100;

function statusLabel(status) {
    switch (status) {
        case 'success':
            return 'Success';
        case 'running':
            return 'In Progress';
        case 'failed':
            return 'Failed';
        default:
            return status ? status.charAt(0).toUpperCase() + status.slice(1) : status;
    }
}

function ExecutionRow({ execution, isSelected, onSelect }) {
    const [page, setPage] = useState(1);
    const lines = execution.message ? execution.message.split('\n') : [];
    const visibleLines = lines.slice(0, page * LOGS_PER_PAGE);
    const hasMore = lines.length > page * LOGS_PER_PAGE;

    return (
        <div className="flex flex-col">
            <a
                className="flex flex-col border-l-2 p-4 cursor-pointer"
                onClick={() => onSelect(execution.id)}
            >
                <div className="flex items-center gap-2 mb-2">
                    <span className="px-3 py-1 rounded-md text-xs font-medium">{statusLabel(execution.status)}</span>
                </div>
                <div className="text-sm text-neutral-500">
                    Started: {execution.startedAt}
                    {execution.status !== 'running' && (
                        <>
                            <br />Ended: {execution.finishedAt}
                            <br />Duration: {execution.duration}
                            <br />Finished {execution.finishedHuman}
                        </>
                    )}
                </div>
            </a>
            {execution.message && (
                <div className="flex flex-col">
                    <a href={execution.downloadUrl}>
                        <button type="button">Download Logs</button>
                    </a>
                </div>
            )}
            {isSelected && (
                <div className="p-4 mb-2 bg-gray-100 dark:bg-coolgray-200 rounded-sm">
                    {execution.status === 'running' && <div className="mb-2">Execution is running...</div>}
                    {visibleLines.length > 0 ? (
                        <div>
                            <h3 className="font-semibold mb-2">Status Message:</h3>
                            <pre className="whitespace-pre-wrap">{visibleLines.join('\n')}</pre>
                            {hasMore && (
                                <button type="button" onClick={() => setPage((p) => p + 1)}>
                                    Load More
                                </button>
                            )}
                        </div>
                    ) : (
                        <div>
                            <div className="font-semibold mb-2">Status Message:</div>
                            <div>No output was recorded for this execution.</div>
                        </div>
                    )}
                    {execution.cleanupLog && (
                        <div className="mt-6 space-y-6">
                            <h3 className="text-lg font-semibold">Cleanup Log:</h3>
                            {execution.cleanupLog.map((result, index) => (
                                <div key={index} className="overflow-hidden rounded-lg border dark:border-coolgray-400">
                                    <div className="flex items-center gap-2 px-4 py-3 bg-gray-50 dark:bg-coolgray-200">
                                        <code className="flex-1 text-sm">{result.command}</code>
                                    </div>
                                    <div className="p-4">
                                        {result.output && result.output.trim() ? (
                                            <pre className="text-sm whitespace-pre-wrap">{result.output}</pre>
                                        ) : (
                                            <p className="text-sm italic">No output returned - command completed successfully</p>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

export default function DockerCleanup({
    serverNavbar,
    sidebar,
    canUpdate,
    isCloud,
    settings,
    isCleanupStale,
    lastExecutionTime,
    isSchedulerHealthy,
    executions: initialExecutions,
    updateUrl,
    manualCleanupUrl,
    executionsUrl,
}) {
    const [executions, setExecutions] = useState(initialExecutions);
    const [selectedId, setSelectedId] = useState(null);
    const [showConfirm, setShowConfirm] = useState(false);
    const pollRef = useRef(null);
    const { data, setData, put, processing, errors } = useForm({
        dockerCleanupFrequency: settings.dockerCleanupFrequency,
        dockerCleanupThreshold: settings.dockerCleanupThreshold,
        forceDockerCleanup: settings.forceDockerCleanup,
        deleteUnusedVolumes: settings.deleteUnusedVolumes,
        deleteUnusedNetworks: settings.deleteUnusedNetworks,
        disableApplicationImageRetention: settings.disableApplicationImageRetention,
    });

    useEffect(() => {
        setExecutions(initialExecutions);
    }, [initialExecutions]);

    async function refreshExecutions() {
        const response = await fetch(executionsUrl, { headers: { Accept: 'application/json' } });
        const result = await response.json();
        setExecutions(result.executions);
    }

    useTeamChannel(['DockerCleanupDone'], () => {
        refreshExecutions();
    });

    useEffect(() => {
        const selected = executions.find((e) => e.id === selectedId);
        if (selected && selected.status === 'running') {
            pollRef.current = setInterval(refreshExecutions, 2000);
        } else if (pollRef.current) {
            clearInterval(pollRef.current);
            pollRef.current = null;
        }

        return () => {
            if (pollRef.current) {
                clearInterval(pollRef.current);
                pollRef.current = null;
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedId, executions]);

    function selectExecution(id) {
        setSelectedId((current) => (current === id ? null : id));
    }

    function submitSettings(e) {
        e.preventDefault();
        put(updateUrl, { preserveScroll: true });
    }

    function instantSave(field, value) {
        setData(field, value);
        router.put(updateUrl, { ...data, [field]: value }, { preserveScroll: true });
    }

    function triggerManualCleanup() {
        setShowConfirm(false);
        router.post(manualCleanupUrl, {}, { preserveScroll: true });
    }

    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ServerSidebar sidebar={sidebar} />
                <div className="w-full">
                    <form onSubmit={submitSettings}>
                        <div className="flex items-center gap-2">
                            <h2>Docker Cleanup</h2>
                            {canUpdate && (
                                <>
                                    <button type="submit" disabled={processing}>
                                        Save
                                    </button>
                                    <button type="button" onClick={() => setShowConfirm(true)}>
                                        Trigger Manual Cleanup
                                    </button>
                                </>
                            )}
                        </div>
                        <div className="mt-1 mb-6">Configure Docker cleanup settings for your server.</div>

                        {!isCloud && isCleanupStale && (
                            <div className="mb-4 p-3 border border-warning/30 bg-warning/10 text-sm rounded">
                                <p>The last Docker cleanup ran {lastExecutionTime ?? 'unknown time'} ago, which is longer than expected for the configured frequency.</p>
                                {!isSchedulerHealthy && (
                                    <p className="mt-1">The scheduled job manager appears to be inactive. This may indicate a stale Redis lock is blocking all scheduled jobs.</p>
                                )}
                                <p className="mt-2">
                                    To resolve, run on your Coolify instance: <code>php artisan cleanup:redis --clear-locks</code>
                                </p>
                            </div>
                        )}

                        <div className="flex flex-col gap-2">
                            <h3>Cleanup Configuration</h3>
                            <div className="flex items-center gap-4">
                                <label className="flex flex-col gap-1">
                                    Docker cleanup frequency
                                    <input
                                        disabled={!canUpdate}
                                        placeholder="*/10 * * * *"
                                        required
                                        value={data.dockerCleanupFrequency}
                                        onChange={(e) => setData('dockerCleanupFrequency', e.target.value)}
                                    />
                                    {errors.dockerCleanupFrequency && <span className="text-error">{errors.dockerCleanupFrequency}</span>}
                                </label>
                                {!data.forceDockerCleanup && (
                                    <label className="flex flex-col gap-1">
                                        Docker cleanup threshold (%)
                                        <input
                                            disabled={!canUpdate}
                                            type="number"
                                            required
                                            value={data.dockerCleanupThreshold}
                                            onChange={(e) => setData('dockerCleanupThreshold', e.target.value)}
                                        />
                                        {errors.dockerCleanupThreshold && <span className="text-error">{errors.dockerCleanupThreshold}</span>}
                                    </label>
                                )}
                            </div>
                            <div className="w-full sm:w-96">
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        disabled={!canUpdate}
                                        checked={data.forceDockerCleanup}
                                        onChange={(e) => instantSave('forceDockerCleanup', e.target.checked)}
                                    />
                                    Force Docker Cleanup
                                </label>
                            </div>
                        </div>

                        <div className="flex flex-col gap-2 mt-6">
                            <h3>Advanced</h3>
                            <div className="p-3 border border-warning/30 bg-warning/10 text-sm rounded">
                                These options can cause permanent data loss and functional issues. Only enable if you fully understand the consequences.
                            </div>
                            <div className="w-full sm:w-96">
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        disabled={!canUpdate}
                                        checked={data.deleteUnusedVolumes}
                                        onChange={(e) => instantSave('deleteUnusedVolumes', e.target.checked)}
                                    />
                                    Delete Unused Volumes
                                </label>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        disabled={!canUpdate}
                                        checked={data.deleteUnusedNetworks}
                                        onChange={(e) => instantSave('deleteUnusedNetworks', e.target.checked)}
                                    />
                                    Delete Unused Networks
                                </label>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        disabled={!canUpdate}
                                        checked={data.disableApplicationImageRetention}
                                        onChange={(e) => instantSave('disableApplicationImageRetention', e.target.checked)}
                                    />
                                    Disable Application Image Retention
                                </label>
                            </div>
                        </div>
                    </form>

                    <div className="mt-8">
                        <h3 className="mb-4">
                            Recent executions <span className="text-xs text-neutral-500">(click to check output)</span>
                        </h3>
                        <div className="flex flex-col gap-2">
                            {executions.length === 0 && (
                                <div className="p-4 bg-gray-100 dark:bg-coolgray-100 rounded-sm">No executions found.</div>
                            )}
                            {executions.map((execution) => (
                                <ExecutionRow
                                    key={execution.id}
                                    execution={execution}
                                    isSelected={execution.id === selectedId}
                                    onSelect={selectExecution}
                                />
                            ))}
                        </div>
                    </div>
                </div>
            </div>

            {showConfirm && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setShowConfirm(false)} />
                    <div className="relative flex w-full flex-col rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                        <h3 className="text-2xl font-bold pb-4">Confirm Docker Cleanup?</h3>
                        <ul className="list-disc pl-4 pb-4 text-sm">
                            <li>Permanently deletes all stopped containers managed by Coolify (as containers are non-persistent, no data will be lost)</li>
                            <li>Permanently deletes all unused images</li>
                            <li>Clears build cache</li>
                            <li>Removes old versions of the Coolify helper image</li>
                            <li>Optionally permanently deletes all unused volumes (if enabled in advanced options)</li>
                            <li>Optionally permanently deletes all unused networks (if enabled in advanced options)</li>
                        </ul>
                        <div className="flex gap-2 justify-end">
                            <button type="button" onClick={() => setShowConfirm(false)}>
                                Cancel
                            </button>
                            <button type="button" onClick={triggerManualCleanup}>
                                Trigger Docker Cleanup
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
