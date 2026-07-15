import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import ApplicationHeading from '../../../../Components/ApplicationHeading';
import ConfigurationChecker from '../../../../Components/ConfigurationChecker';

const STATUS_LABEL = {
    in_progress: 'In Progress',
    queued: 'Queued',
    finished: 'Finished',
    failed: 'Failed',
    'cancelled-by-user': 'Cancelled by User',
};

/**
 * Simplified port of livewire/project/application/deployment/show.blade.php. Known v1
 * gaps: SVG action icons are ported as plain text buttons (matches this migration's
 * established action-bar convention, e.g. ExecutionCard.jsx); DeploymentNavbar's
 * copyLogsToClipboard() is genuinely dead code (never called from the Blade view) and
 * isn't ported.
 */
export default function Show({ application, heading, configurationChecker, deployment, isDebugEnabled, isKeepAliveOn, logLines, parameters, urls }) {
    const [fullscreen, setFullscreen] = useState(false);
    const [alwaysScroll, setAlwaysScroll] = useState(isKeepAliveOn);
    const [showTimestamps, setShowTimestamps] = useState(true);
    const [searchQuery, setSearchQuery] = useState('');
    const [downloadMenuOpen, setDownloadMenuOpen] = useState(false);
    const logsContainerRef = useRef(null);
    const pollRef = useRef(null);
    const wasKeepAliveOn = useRef(isKeepAliveOn);

    // Mirrors the original's wire:poll.2000ms="polling" (only active while the
    // deployment isn't finished/failed).
    useEffect(() => {
        if (!isKeepAliveOn) return;
        pollRef.current = setInterval(() => {
            router.reload({ only: ['deployment', 'logLines', 'isKeepAliveOn'], preserveScroll: true, preserveState: true });
        }, 2000);

        return () => clearInterval(pollRef.current);
    }, [isKeepAliveOn]);

    // Mirrors the original's `deploymentFinished` event: stop auto-scroll shortly
    // after the deployment stops being "keep alive".
    useEffect(() => {
        if (wasKeepAliveOn.current && !isKeepAliveOn) {
            const t = setTimeout(() => setAlwaysScroll(false), 500);

            return () => clearTimeout(t);
        }
        wasKeepAliveOn.current = isKeepAliveOn;
    }, [isKeepAliveOn]);

    const query = searchQuery.trim().toLowerCase();

    const displayLines = useMemo(
        () => logLines.map((line) => ({ ...line, text: (line.command ? '[CMD]: ' : '') + line.line })),
        [logLines]
    );

    const visibleLines = useMemo(() => {
        if (!query) return displayLines;

        return displayLines.filter((line) => `${line.timestamp} ${line.text}`.toLowerCase().includes(query));
    }, [displayLines, query]);

    const matchCount = query ? visibleLines.length : 0;

    useEffect(() => {
        if (alwaysScroll && logsContainerRef.current) {
            logsContainerRef.current.scrollTop = logsContainerRef.current.scrollHeight;
        }
    }, [visibleLines, alwaysScroll]);

    function handleScroll(e) {
        const el = e.target;
        const distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight;
        if (!alwaysScroll && distanceFromBottom <= 10) {
            setAlwaysScroll(true);
        }
    }

    function handleWheel(e) {
        if (alwaysScroll && e.deltaY < 0) {
            setAlwaysScroll(false);
        }
    }

    function highlight(text) {
        if (!query) return text;
        const lower = text.toLowerCase();
        const parts = [];
        let lastIndex = 0;
        let index = lower.indexOf(query);
        let key = 0;
        while (index !== -1) {
            if (index > lastIndex) parts.push(text.slice(lastIndex, index));
            parts.push(
                <span key={key++} className="log-highlight">
                    {text.slice(index, index + query.length)}
                </span>
            );
            lastIndex = index + query.length;
            index = lower.indexOf(query, lastIndex);
        }
        if (lastIndex < text.length) parts.push(text.slice(lastIndex));

        return parts;
    }

    function collectVisibleLogsText() {
        return visibleLines.map((line) => (showTimestamps ? `${line.timestamp} ${line.text}` : line.text)).join('\n');
    }

    function copyLogs() {
        const content = collectVisibleLogsText();
        if (!content || !navigator.clipboard?.writeText) return;
        navigator.clipboard.writeText(content);
    }

    function downloadDisplayedLogs() {
        const content = collectVisibleLogsText();
        if (!content) return;
        const blob = new Blob([content], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        const timestamp = new Date().toISOString().slice(0, 19).replace(/[T:]/g, '-');
        a.download = `deployment-${deployment.deployment_uuid}-${timestamp}.txt`;
        a.click();
        URL.revokeObjectURL(url);
        setDownloadMenuOpen(false);
    }

    function toggleDebug() {
        router.post(urls.toggleDebug, {}, { preserveScroll: true });
    }

    function forceStart() {
        router.post(urls.forceStart, {}, { preserveScroll: true });
    }

    function cancel() {
        router.post(urls.cancel, {}, { preserveScroll: true });
    }

    return (
        <div className="flex h-[calc(100vh-10rem)] min-h-[50rem] flex-col overflow-hidden">
            <h1 className="py-0">Deployment</h1>
            <ConfigurationChecker configurationChecker={configurationChecker} />
            <ApplicationHeading application={application} heading={heading} parameters={parameters} urls={urls} />

            <div className="flex flex-1 min-h-0 flex-col overflow-hidden">
                <div className="flex items-center gap-2 pb-4">
                    <h2>Deployment Log</h2>
                    {deployment.status === 'queued' && (
                        <button type="button" onClick={forceStart}>
                            Force Start
                        </button>
                    )}
                    {(deployment.status === 'in_progress' || deployment.status === 'queued') && (
                        <button type="button" className="text-error" onClick={cancel}>
                            Cancel
                        </button>
                    )}
                </div>

                <div className={fullscreen ? 'fullscreen flex flex-col' : 'mt-4 flex flex-1 min-h-0 flex-col overflow-hidden'}>
                    <div
                        className={
                            'flex min-h-0 flex-col w-full overflow-hidden bg-white dark:text-white dark:bg-coolgray-100 dark:border-coolgray-300' +
                            (fullscreen ? ' h-full' : ' flex-1 border border-dotted rounded-sm')
                        }
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2 px-4 py-2 border-b dark:border-coolgray-300 border-neutral-200 shrink-0">
                            <div className="flex items-center gap-3">
                                <div className="flex items-center gap-1">
                                    <span>Deployment is</span>
                                    <span className="dark:text-warning">{STATUS_LABEL[deployment.status] ?? deployment.status}</span>
                                </div>
                                {query && <span className="text-xs text-gray-500 dark:text-gray-400">{matchCount} matches</span>}
                            </div>
                            <div className="flex flex-wrap items-center justify-end gap-2">
                                <input
                                    id="deployment-log-search"
                                    name="deployment-log-search"
                                    type="text"
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    placeholder="Find in logs"
                                    className="input input-sm w-48"
                                />
                                {searchQuery && (
                                    <button type="button" onClick={() => setSearchQuery('')}>
                                        Clear
                                    </button>
                                )}
                                <button type="button" title="Copy Logs" onClick={copyLogs}>
                                    Copy
                                </button>
                                <div className="relative">
                                    <button type="button" title="Download Logs" onClick={() => setDownloadMenuOpen((v) => !v)}>
                                        Download
                                    </button>
                                    {downloadMenuOpen && (
                                        <div className="absolute right-0 z-50 mt-2 w-max rounded-md border bg-white dark:bg-coolgray-200 dark:border-coolgray-300 shadow-lg">
                                            <div className="py-1">
                                                <button
                                                    type="button"
                                                    className="block w-full px-4 py-2 text-left text-sm"
                                                    onClick={downloadDisplayedLogs}
                                                >
                                                    Download displayed logs
                                                </button>
                                                <a
                                                    className="block w-full px-4 py-2 text-left text-sm"
                                                    href={urls.downloadAllLogs}
                                                    onClick={() => setDownloadMenuOpen(false)}
                                                >
                                                    Download all logs
                                                </a>
                                            </div>
                                        </div>
                                    )}
                                </div>
                                <button
                                    type="button"
                                    title="Toggle Timestamps"
                                    className={showTimestamps ? '!text-warning' : ''}
                                    onClick={() => setShowTimestamps((v) => !v)}
                                >
                                    Timestamps
                                </button>
                                <button
                                    type="button"
                                    title={isDebugEnabled ? 'Hide Debug Logs' : 'Show Debug Logs'}
                                    className={isDebugEnabled ? '!text-warning' : ''}
                                    onClick={toggleDebug}
                                >
                                    Debug
                                </button>
                                <button
                                    type="button"
                                    title="Follow Logs"
                                    className={alwaysScroll ? '!text-warning' : ''}
                                    onClick={() => setAlwaysScroll((v) => !v)}
                                >
                                    Follow
                                </button>
                                <button type="button" title={fullscreen ? 'Minimize' : 'Fullscreen'} onClick={() => setFullscreen((v) => !v)}>
                                    {fullscreen ? 'Minimize' : 'Fullscreen'}
                                </button>
                            </div>
                        </div>
                        <div
                            ref={logsContainerRef}
                            onScroll={handleScroll}
                            onWheel={handleWheel}
                            className="flex min-h-40 flex-1 flex-col overflow-y-auto p-2 px-4 scrollbar"
                        >
                            <div className="flex flex-col font-logs">
                                {query && matchCount === 0 && <div className="text-gray-500 dark:text-gray-400 py-2">No matches found.</div>}
                                {logLines.length === 0 ? (
                                    <span className="font-logs text-neutral-400 mb-2">No logs yet.</span>
                                ) : (
                                    visibleLines.map((line, i) => (
                                        <div key={i} className={'flex gap-2 log-line' + (line.command ? ' mt-2' : '')}>
                                            {showTimestamps && <span className="shrink-0 text-gray-500">{line.timestamp}</span>}
                                            <span
                                                className={
                                                    'whitespace-pre-wrap' +
                                                    (line.hidden ? ' text-success dark:text-warning' : '') +
                                                    (line.stderr ? ' text-red-500' : '') +
                                                    (line.command ? ' font-bold' : '')
                                                }
                                            >
                                                {highlight(line.text)}
                                            </span>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
