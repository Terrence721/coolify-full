import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

const LEVEL_COLORS = {
    error: 'bg-red-500',
    warning: 'bg-yellow-500',
    debug: 'bg-purple-500',
    info: 'bg-blue-500',
};

function getLogLevel(content) {
    const lower = content.toLowerCase();
    if (/\b(error|err|failed|failure|exception|fatal|panic|critical)\b/.test(lower)) return 'error';
    if (/\b(warn|warning|wrn|caution)\b/.test(lower)) return 'warning';
    if (/\b(debug|dbg|trace|verbose)\b/.test(lower)) return 'debug';

    return 'info';
}

/**
 * Simplified port of livewire/project/shared/get-logs.blade.php. `reloadKeys`/`queryPrefix`
 * let a page render several independent instances (Project/Shared/Logs, one per
 * container) without their partial reloads/query params colliding — Server\Proxy\Logs
 * and Server\Sentinel\Logs (a single fixed container each) just use the defaults.
 * Known v1 gap: the collapsible/expand-on-click variant (used when GetLogs is nested
 * per-container in the original) isn't ported — every container's logs are always
 * fetched eagerly instead of lazily on expand.
 */
export default function ContainerLogs({
    displayName,
    logLines,
    numberOfLines,
    showTimestamps,
    urls,
    reloadKeys = ['logLines'],
    queryPrefix = '',
}) {
    const [fullscreen, setFullscreen] = useState(false);
    const [alwaysScroll, setAlwaysScroll] = useState(false);
    const [streaming, setStreaming] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const [lines, setLines] = useState(numberOfLines);
    const [colorLogs, setColorLogs] = useState(() => localStorage.getItem('coolify-color-logs') === 'true');
    const [logFilters, setLogFilters] = useState(() => {
        try {
            return JSON.parse(localStorage.getItem('coolify-log-filters')) ?? { error: true, warning: true, debug: true, info: true };
        } catch (_) {
            return { error: true, warning: true, debug: true, info: true };
        }
    });
    const [filterMenuOpen, setFilterMenuOpen] = useState(false);
    const [downloadMenuOpen, setDownloadMenuOpen] = useState(false);
    const logsContainerRef = useRef(null);
    const pollRef = useRef(null);

    useEffect(() => {
        if (!streaming) return;
        pollRef.current = setInterval(() => {
            router.reload({ only: reloadKeys, preserveScroll: true, preserveState: true });
        }, 2000);

        return () => clearInterval(pollRef.current);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [streaming]);

    const query = searchQuery.trim().toLowerCase();

    const displayLines = useMemo(
        () => logLines.map((line) => ({ ...line, level: getLogLevel(line.line) })),
        [logLines]
    );

    const visibleLines = useMemo(
        () =>
            displayLines.filter((line) => {
                if (logFilters[line.level] === false) return false;

                return !query || line.line.toLowerCase().includes(query);
            }),
        [displayLines, logFilters, query]
    );

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
        return visibleLines.map((line) => (showTimestamps && line.timestamp ? `${line.timestamp} ${line.line}` : line.line)).join('\n');
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
        a.download = `logs-${timestamp}.txt`;
        a.click();
        URL.revokeObjectURL(url);
        setDownloadMenuOpen(false);
    }

    function refresh() {
        router.reload({ only: reloadKeys, preserveScroll: true, preserveState: true });
    }

    function applySettings(newLines, newTimestamps) {
        router.get(
            window.location.pathname,
            { ...currentQueryParams(), [`${queryPrefix}lines`]: newLines, [`${queryPrefix}timestamps`]: newTimestamps ? 1 : 0 },
            { preserveState: true, preserveScroll: true, only: reloadKeys }
        );
    }

    function currentQueryParams() {
        return Object.fromEntries(new URLSearchParams(window.location.search));
    }

    function submitLines(e) {
        e.preventDefault();
        applySettings(lines, showTimestamps);
    }

    function toggleTimestamps() {
        applySettings(lines, !showTimestamps);
    }

    function toggleLogFilter(level) {
        setLogFilters((prev) => {
            const next = { ...prev, [level]: !prev[level] };
            localStorage.setItem('coolify-log-filters', JSON.stringify(next));

            return next;
        });
    }

    function toggleColorLogs() {
        setColorLogs((prev) => {
            localStorage.setItem('coolify-color-logs', String(!prev));

            return !prev;
        });
    }

    return (
        <div className={fullscreen ? 'fullscreen flex flex-col' : 'relative w-full'}>
            <div className="flex flex-col dark:text-white dark:border-coolgray-300 border-neutral-200 bg-white dark:bg-coolgray-100 border border-solid rounded-sm">
                <div className="flex flex-wrap items-center justify-between gap-2 px-4 py-2 border-b dark:border-coolgray-300 border-neutral-200 shrink-0">
                    <div className="flex items-center gap-2">
                        {displayName && <h4>{displayName}</h4>}
                        <form onSubmit={submitLines} className="relative flex items-center">
                            <span className="absolute left-2 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none">Lines:</span>
                            <input
                                id={`${queryPrefix}logs-lines`}
                                name={`${queryPrefix}logs-lines`}
                                type="number"
                                min={1}
                                max={50000}
                                title="Number of Lines (max 50,000)"
                                readOnly={streaming}
                                value={lines}
                                onChange={(e) => setLines(Number(e.target.value))}
                                className="input input-sm w-32 pl-11"
                            />
                        </form>
                        {query && <span className="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">{matchCount} matches</span>}
                    </div>
                    <div className="flex flex-wrap items-center justify-end gap-2">
                        <input
                            id={`${queryPrefix}logs-search`}
                            name={`${queryPrefix}logs-search`}
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
                        <button type="button" title="Refresh Logs" disabled={streaming} onClick={refresh}>
                            Refresh
                        </button>
                        <button type="button" title={streaming ? 'Stop Streaming' : 'Stream Logs'} className={streaming ? '!text-warning' : ''} onClick={() => setStreaming((v) => !v)}>
                            {streaming ? 'Stop' : 'Stream'}
                        </button>
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
                                        <button type="button" className="block w-full px-4 py-2 text-left text-sm" onClick={downloadDisplayedLogs}>
                                            Download displayed logs
                                        </button>
                                        <a
                                            className="block w-full px-4 py-2 text-left text-sm"
                                            href={urls.downloadAll}
                                            onClick={() => setDownloadMenuOpen(false)}
                                        >
                                            Download all logs
                                        </a>
                                    </div>
                                </div>
                            )}
                        </div>
                        <button type="button" title="Toggle Timestamps" className={showTimestamps ? '!text-warning' : ''} onClick={toggleTimestamps}>
                            Timestamps
                        </button>
                        <button type="button" title="Toggle Log Colors" className={colorLogs ? '!text-warning' : ''} onClick={toggleColorLogs}>
                            Colors
                        </button>
                        <div className="relative">
                            <button
                                type="button"
                                title="Filter Log Levels"
                                className={Object.values(logFilters).some((v) => !v) ? '!text-warning' : ''}
                                onClick={() => setFilterMenuOpen((v) => !v)}
                            >
                                Filter
                            </button>
                            {filterMenuOpen && (
                                <div className="absolute right-0 z-50 mt-2 w-max rounded-md border bg-white dark:bg-coolgray-200 dark:border-coolgray-300 shadow-lg">
                                    <div className="py-1">
                                        {Object.keys(LEVEL_COLORS).map((level) => (
                                            <label key={level} className="flex items-center gap-2 px-4 py-1.5 text-sm cursor-pointer select-none">
                                                <input id={`${queryPrefix}logs-filter-${level}`} type="checkbox" checked={logFilters[level]} onChange={() => toggleLogFilter(level)} />
                                                <span className={`w-2.5 h-2.5 rounded-full ${LEVEL_COLORS[level]}`} />
                                                {level.charAt(0).toUpperCase() + level.slice(1)}
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                        <button type="button" title="Follow Logs" className={alwaysScroll ? '!text-warning' : ''} onClick={() => setAlwaysScroll((v) => !v)}>
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
                    className="flex overflow-y-auto overflow-x-hidden flex-col px-4 py-2 w-full min-w-0 scrollbar"
                    style={fullscreen ? { flex: 1 } : { maxHeight: '40rem' }}
                >
                    {logLines.length === 0 ? (
                        <pre className="font-logs whitespace-pre-wrap break-all max-w-full text-neutral-400">No logs yet.</pre>
                    ) : (
                        <div className="font-logs max-w-full cursor-default">
                            {query && matchCount === 0 && <div className="text-gray-500 dark:text-gray-400 py-2">No matches found.</div>}
                            {visibleLines.map((line, i) => (
                                <div key={i} className={'flex gap-2 log-line' + (colorLogs ? ` log-${line.level}` : '')}>
                                    {showTimestamps && line.timestamp && <span className="shrink-0 text-gray-500">{line.timestamp}</span>}
                                    <span className="whitespace-pre-wrap break-all">{highlight(line.line)}</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
