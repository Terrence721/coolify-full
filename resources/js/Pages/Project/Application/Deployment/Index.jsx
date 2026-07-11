import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { useTeamChannel } from '../../../../hooks/useTeamChannel';
import Heading from './Heading';
import ConfigurationChecker from '../../../../Components/ConfigurationChecker';

const STATUS_LABELS = {
    in_progress: 'In Progress',
    queued: 'Queued',
    failed: 'Failed',
    finished: 'Success',
    'cancelled-by-user': 'Cancelled',
};

const STATUS_BORDER = {
    in_progress: 'border-blue-500/50 border-dashed',
    queued: 'border-purple-500/50 border-dashed',
    'cancelled-by-user': 'border-white border-dashed',
    failed: 'border-error',
    finished: 'border-success',
};

const STATUS_BADGE = {
    in_progress: 'bg-blue-100/80 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300',
    queued: 'bg-purple-100/80 text-purple-700 dark:bg-purple-500/20 dark:text-purple-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200',
    finished: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200',
    'cancelled-by-user': 'bg-gray-100 text-gray-700 dark:bg-gray-600/30 dark:text-gray-300',
};

export default function Index({
    application,
    heading,
    configurationChecker,
    deployments,
    deploymentsCount,
    skip,
    defaultTake,
    currentPage,
    showNext,
    showPrev,
    pullRequestId,
    baseUrl,
    urls,
    parameters,
}) {
    const { data, setData } = useForm({ pull_request_id: pullRequestId ?? '' });
    const pollRef = useRef(null);

    useTeamChannel(['ServiceChecked'], () => {
        router.reload({ only: ['deployments', 'deploymentsCount', 'showNext', 'showPrev'] });
    });

    // Mirrors the original's wire:poll.5000ms="reloadDeployments" (only active on the
    // first page, matching `@if (!$skip)`).
    useEffect(() => {
        if (skip) return;
        pollRef.current = setInterval(() => {
            router.reload({ only: ['deployments', 'deploymentsCount', 'showNext', 'showPrev'], preserveScroll: true });
        }, 5000);

        return () => clearInterval(pollRef.current);
    }, [skip]);

    function reload(params) {
        router.get(window.location.pathname, params, { preserveState: true, preserveScroll: true });
    }

    function previousPage() {
        reload({ skip: Math.max(0, skip - defaultTake), pull_request_id: pullRequestId });
    }

    function nextPage() {
        reload({ skip: skip + defaultTake, pull_request_id: pullRequestId });
    }

    function submitFilter(e) {
        e.preventDefault();
        reload({ skip: 0, pull_request_id: data.pull_request_id || null });
    }

    function clearFilter() {
        setData('pull_request_id', '');
        reload({ skip: 0, pull_request_id: null });
    }

    return (
        <div>
            <h1>Deployments</h1>
            <ConfigurationChecker configurationChecker={configurationChecker} />
            <Heading application={application} heading={heading} parameters={parameters} urls={urls} />

            <div className="flex flex-col gap-2 pb-10">
                <div className="flex items-end gap-2">
                    <h2>
                        Deployments <span className="text-xs">({deploymentsCount})</span>
                    </h2>
                    {deploymentsCount > 0 && (
                        <div className="flex items-center gap-2">
                            <button type="button" disabled={!showPrev} onClick={previousPage}>
                                ←
                            </button>
                            <span className="text-sm opacity-70 px-2">
                                Page {currentPage} of {Math.ceil(deploymentsCount / defaultTake)}
                            </span>
                            <button type="button" disabled={!showNext} onClick={nextPage}>
                                →
                            </button>
                        </div>
                    )}
                </div>
                <form onSubmit={submitFilter} className="flex items-end gap-2">
                    <label className="flex flex-col gap-1">
                        Pull Request Id
                        <input
                            type="number"
                            min="1"
                            value={data.pull_request_id}
                            onChange={(e) => setData('pull_request_id', e.target.value)}
                        />
                    </label>
                    <button type="submit">Filter</button>
                    {pullRequestId && (
                        <button type="button" onClick={clearFilter}>
                            Clear
                        </button>
                    )}
                </form>

                {deployments.length === 0 ? (
                    <div>No deployments found</div>
                ) : (
                    deployments.map((deployment) => (
                        <div
                            key={deployment.deployment_uuid}
                            className={`p-2 border-l-2 bg-white dark:bg-coolgray-100 ${STATUS_BORDER[deployment.status] ?? ''}`}
                        >
                            <a href={`${baseUrl}/${deployment.deployment_uuid}`} className="block">
                                <div className="flex flex-col">
                                    <div className="flex items-center gap-2 mb-2">
                                        <span className={`px-3 py-1 rounded-md text-xs font-medium shadow-xs ${STATUS_BADGE[deployment.status] ?? ''}`}>
                                            {STATUS_LABELS[deployment.status] ?? deployment.status}
                                        </span>
                                    </div>
                                    {deployment.status !== 'queued' && (
                                        <div className="text-sm opacity-70">
                                            Started: {deployment.started_at}
                                            {deployment.finished_at && (
                                                <>
                                                    <br />
                                                    Ended: {deployment.finished_at}
                                                    <br />
                                                    Duration: {deployment.duration}
                                                    <br />
                                                    Finished {deployment.finished_ago}
                                                </>
                                            )}
                                            {deployment.status === 'in_progress' && (
                                                <>
                                                    <br />
                                                    Running for: {deployment.duration}
                                                </>
                                            )}
                                        </div>
                                    )}
                                    {deployment.commit && (
                                        <div className="text-sm opacity-70 mt-2 flex items-center gap-2">
                                            <span className="font-medium">Commit:</span>
                                            <a href={deployment.commit_link} target="_blank" rel="noreferrer" className="underline">
                                                {deployment.commit.slice(0, 7)}
                                            </a>
                                            {deployment.commit_message && <span>- {deployment.commit_message.split('\n')[0]}</span>}
                                        </div>
                                    )}
                                    {deployment.server_name && deployment.has_additional_servers && (
                                        <div className="text-sm opacity-70 mt-2">Server: {deployment.server_name}</div>
                                    )}
                                </div>
                            </a>
                        </div>
                    ))
                )}
            </div>
        </div>
    );
}
