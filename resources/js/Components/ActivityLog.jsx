import { useEffect, useRef, useState } from 'react';

/**
 * React port of ActivityMonitor.php's polling loop (app/Livewire/ActivityMonitor.php,
 * resources/views/livewire/activity-monitor.blade.php), scoped to what ServerNavbar.jsx's
 * proxy-startup-log slide-over needs: poll an activity's decoded output + exit code every
 * second, auto-scroll, stop polling once the exit code is known. The original component's
 * generic "dispatch an arbitrary event/broadcast class on completion" behavior is not ported —
 * only the plain local "call onFinished" case, which is all ServerNavbar needs (Navbar's
 * `newMonitorActivity()` call never overrides the default `eventToDispatch`).
 */
export default function ActivityLog({ activityId, header, fullHeight = false, showWaiting = true, onFinished }) {
    const [output, setOutput] = useState('');
    const [isPolling, setIsPolling] = useState(false);
    const preRef = useRef(null);
    const autoScrollRef = useRef(true);

    useEffect(() => {
        if (!activityId) {
            setOutput('');
            setIsPolling(false);
            return;
        }

        setIsPolling(true);
        let cancelled = false;

        async function poll() {
            try {
                const response = await fetch(`/activity/${activityId}`, {
                    headers: { Accept: 'application/json' },
                });
                const data = await response.json();
                if (cancelled) return;

                if (data.found) {
                    setOutput(data.output);
                }

                if (data.exitCode !== null && data.exitCode !== undefined) {
                    setIsPolling(false);
                    if (onFinished) onFinished(data.exitCode);
                    return;
                }
            } catch {
                // ignore — retry on next tick
            }

            if (!cancelled) {
                timeoutId = setTimeout(poll, 1000);
            }
        }

        let timeoutId = setTimeout(poll, 0);

        return () => {
            cancelled = true;
            clearTimeout(timeoutId);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [activityId]);

    useEffect(() => {
        if (autoScrollRef.current && preRef.current) {
            preRef.current.parentElement.scrollTop = preRef.current.parentElement.scrollHeight;
        }
    }, [output]);

    function handleScroll(e) {
        const el = e.target;
        const threshold = 5;
        autoScrollRef.current = el.scrollTop + el.clientHeight >= el.scrollHeight - threshold;
    }

    if (!activityId) {
        return showWaiting ? <div className="flex justify-start">Waiting for the process to start...</div> : null;
    }

    return (
        <div className={fullHeight ? 'h-full flex flex-col overflow-hidden' : 'h-full overflow-hidden'}>
            {header && (
                <div className="flex gap-2 pb-2 flex-shrink-0">
                    <h3>{header}</h3>
                    {isPolling && <span className="text-xs opacity-70">(running...)</span>}
                </div>
            )}
            <div
                onScroll={handleScroll}
                className={
                    'flex flex-col w-full px-4 py-2 overflow-y-auto bg-white border border-solid rounded-sm dark:text-white dark:bg-coolgray-100 scrollbar border-neutral-300 dark:border-coolgray-300' +
                    (fullHeight ? ' flex-1 min-h-0' : ' max-h-96')
                }
            >
                <pre ref={preRef} className="font-logs whitespace-pre-wrap">
                    {output}
                </pre>
            </div>
        </div>
    );
}
