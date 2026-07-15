import { router } from '@inertiajs/react';
import { useState } from 'react';

const TYPE_STYLES = {
    backup: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
    task: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300',
    cleanup: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
    docker_cleanup: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
};

const REASON_LABELS = {
    server_not_functional: 'Server not functional',
    database_deleted: 'Database deleted',
    server_deleted: 'Server deleted',
    resource_deleted: 'Resource deleted',
    application_not_running: 'Application not running',
    service_not_running: 'Service not running',
};

function reload(params) {
    router.get('/settings/scheduled-jobs', params, { preserveState: true });
}

export default function ScheduledJobs({
    filterType,
    filterDate,
    executions,
    managerRuns,
    skipLogs,
    skipTotalCount,
    skipDefaultTake,
    skipPage,
    skipCurrentPage,
    showSkipPrev,
    showSkipNext,
}) {
    const [activeTab, setActiveTab] = useState('executions');

    return (
        <div>
            <div className="pb-5">
                <h1>Settings</h1>
                <div className="subtitle">Instance wide settings for Coolify.</div>
                <div className="navbar-main">
                    <nav className="flex items-center gap-6 min-h-10 whitespace-nowrap">
                        <a href="/settings">Configuration</a>
                        <a href="/settings/backup">Backup</a>
                        <a href="/settings/email">Transactional Email</a>
                        <a href="/settings/oauth">OAuth</a>
                        <a href="/settings/scheduled-jobs" className="dark:text-white">
                            Scheduled Jobs
                        </a>
                    </nav>
                </div>
            </div>

            <div className="flex flex-col gap-8">
                <div>
                    <div className="flex items-center gap-2">
                        <h2>Scheduled Job Issues</h2>
                        <button type="button" onClick={() => reload({ filterType, filterDate, skipPage })}>
                            Refresh
                        </button>
                    </div>
                    <div className="pb-4">Shows failed executions, skipped jobs, and scheduler health.</div>
                </div>

                <div className="flex flex-row gap-4">
                    <div
                        className={`box-without-bg cursor-pointer dark:bg-coolgray-100 dark:text-white w-full text-center items-center justify-center ${activeTab === 'executions' ? 'dark:bg-coollabs bg-coollabs text-white' : ''}`}
                        onClick={() => setActiveTab('executions')}
                    >
                        Failures ({executions.length})
                    </div>
                    <div
                        className={`box-without-bg cursor-pointer dark:bg-coolgray-100 dark:text-white w-full text-center items-center justify-center ${activeTab === 'scheduler-runs' ? 'dark:bg-coollabs bg-coollabs text-white' : ''}`}
                        onClick={() => setActiveTab('scheduler-runs')}
                    >
                        Scheduler Runs ({managerRuns.length})
                    </div>
                    <div
                        className={`box-without-bg cursor-pointer dark:bg-coolgray-100 dark:text-white w-full text-center items-center justify-center ${activeTab === 'skipped-jobs' ? 'dark:bg-coollabs bg-coollabs text-white' : ''}`}
                        onClick={() => setActiveTab('skipped-jobs')}
                    >
                        Skipped Jobs ({skipTotalCount})
                    </div>
                </div>

                {activeTab === 'executions' && (
                    <div>
                        <div className="flex gap-4 flex-wrap mb-4">
                            <label className="flex flex-col gap-1 text-sm font-medium">
                                Type
                                <select
                                    id="scheduled-jobs-filter-type"
                                    name="scheduled-jobs-filter-type"
                                    className="w-40"
                                    value={filterType}
                                    onChange={(e) => reload({ filterType: e.target.value, filterDate, skipPage: 0 })}
                                >
                                    <option value="all">All Types</option>
                                    <option value="backup">Backups</option>
                                    <option value="task">Tasks</option>
                                    <option value="cleanup">Docker Cleanup</option>
                                </select>
                            </label>
                            <label className="flex flex-col gap-1 text-sm font-medium">
                                Time Range
                                <select
                                    id="scheduled-jobs-filter-date"
                                    name="scheduled-jobs-filter-date"
                                    className="w-40"
                                    value={filterDate}
                                    onChange={(e) => reload({ filterType, filterDate: e.target.value, skipPage: 0 })}
                                >
                                    <option value="last_24h">Last 24 Hours</option>
                                    <option value="last_7d">Last 7 Days</option>
                                    <option value="last_30d">Last 30 Days</option>
                                    <option value="all">All Time</option>
                                </select>
                            </label>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full text-sm text-left">
                                <thead className="text-xs uppercase bg-gray-50 dark:bg-coolgray-200">
                                    <tr>
                                        <th className="px-4 py-3">Type</th>
                                        <th className="px-4 py-3">Resource</th>
                                        <th className="px-4 py-3">Server</th>
                                        <th className="px-4 py-3">Started</th>
                                        <th className="px-4 py-3">Duration</th>
                                        <th className="px-4 py-3">Message</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {executions.length === 0 ? (
                                        <tr>
                                            <td colSpan={6} className="px-4 py-8 text-center text-gray-500">
                                                No failures found for the selected filters.
                                            </td>
                                        </tr>
                                    ) : (
                                        executions.map((execution) => (
                                            <tr key={`exec-${execution.type}-${execution.id}`} className="border-b border-gray-200 dark:border-coolgray-400">
                                                <td className="px-4 py-3">
                                                    <span className={`px-2 py-1 rounded-md text-xs font-medium ${TYPE_STYLES[execution.type] ?? ''}`}>
                                                        {execution.type.charAt(0).toUpperCase() + execution.type.slice(1)}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    {execution.resource_name}
                                                    {execution.resource_type && (
                                                        <span className="text-xs text-gray-500"> ({execution.resource_type})</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">{execution.server_name}</td>
                                                <td className="px-4 py-3 whitespace-nowrap">
                                                    {execution.created_at_human}
                                                    <span className="block text-xs text-gray-500">{execution.created_at_formatted}</span>
                                                </td>
                                                <td className="px-4 py-3 whitespace-nowrap">
                                                    {execution.duration_seconds !== null ? `${execution.duration_seconds}s` : execution.status === 'running' ? '…' : '-'}
                                                </td>
                                                <td className="px-4 py-3 max-w-xs truncate" title={execution.message}>
                                                    {execution.message}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {activeTab === 'scheduler-runs' && (
                    <div>
                        <div className="pb-4 text-sm text-gray-500">
                            Shows when the ScheduledJobManager executed. Gaps indicate lock conflicts or missed runs.
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm text-left">
                                <thead className="text-xs uppercase bg-gray-50 dark:bg-coolgray-200">
                                    <tr>
                                        <th className="px-4 py-3">Time</th>
                                        <th className="px-4 py-3">Event</th>
                                        <th className="px-4 py-3">Duration</th>
                                        <th className="px-4 py-3">Dispatched</th>
                                        <th className="px-4 py-3">Skipped</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {managerRuns.length === 0 ? (
                                        <tr>
                                            <td colSpan={5} className="px-4 py-4 text-center text-gray-500">
                                                No scheduler run logs found. Logs appear after the ScheduledJobManager runs.
                                            </td>
                                        </tr>
                                    ) : (
                                        managerRuns.map((run, index) => (
                                            <tr key={`run-${index}`} className="border-b border-gray-200 dark:border-coolgray-400">
                                                <td className="px-4 py-2 whitespace-nowrap text-xs">{run.timestamp}</td>
                                                <td className="px-4 py-2">{run.message}</td>
                                                <td className="px-4 py-2">{run.duration_ms !== null ? `${run.duration_ms}ms` : '-'}</td>
                                                <td className="px-4 py-2">{run.dispatched ?? '-'}</td>
                                                <td className="px-4 py-2">
                                                    {(run.skipped ?? 0) > 0 ? <span className="text-warning">{run.skipped}</span> : (run.skipped ?? '-')}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {activeTab === 'skipped-jobs' && (
                    <div>
                        <div className="pb-4 text-sm text-gray-500">Jobs that were not dispatched because conditions were not met.</div>
                        {skipTotalCount > skipDefaultTake && (
                            <div className="flex items-center gap-2 mb-4">
                                <button
                                    type="button"
                                    disabled={!showSkipPrev}
                                    onClick={() => reload({ filterType, filterDate, skipPage: Math.max(0, skipPage - skipDefaultTake) })}
                                >
                                    ←
                                </button>
                                <span className="text-sm">
                                    Page {skipCurrentPage} of {Math.ceil(skipTotalCount / skipDefaultTake)}
                                </span>
                                <button
                                    type="button"
                                    disabled={!showSkipNext}
                                    onClick={() => reload({ filterType, filterDate, skipPage: skipPage + skipDefaultTake })}
                                >
                                    →
                                </button>
                            </div>
                        )}
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm text-left">
                                <thead className="text-xs uppercase bg-gray-50 dark:bg-coolgray-200">
                                    <tr>
                                        <th className="px-4 py-3">Time</th>
                                        <th className="px-4 py-3">Type</th>
                                        <th className="px-4 py-3">Resource</th>
                                        <th className="px-4 py-3">Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {skipLogs.length === 0 ? (
                                        <tr>
                                            <td colSpan={4} className="px-4 py-4 text-center text-gray-500">
                                                No skipped jobs found. This means all scheduled jobs passed their conditions.
                                            </td>
                                        </tr>
                                    ) : (
                                        skipLogs.map((skip, index) => (
                                            <tr key={`skip-${index}`} className="border-b border-gray-200 dark:border-coolgray-400">
                                                <td className="px-4 py-2 whitespace-nowrap text-xs">{skip.timestamp}</td>
                                                <td className="px-4 py-2">
                                                    <span className={`px-2 py-1 rounded-md text-xs font-medium ${TYPE_STYLES[skip.type] ?? ''}`}>
                                                        {skip.type.replace('_', ' ')}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-2">
                                                    {skip.link ? (
                                                        <a href={skip.link} className="underline hover:no-underline">
                                                            {skip.resource_name}
                                                        </a>
                                                    ) : (
                                                        skip.resource_name || skip.context?.task_name || skip.context?.server_name || 'Deleted'
                                                    )}
                                                </td>
                                                <td className="px-4 py-2">{REASON_LABELS[skip.reason] ?? skip.reason.replace('_', ' ')}</td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
