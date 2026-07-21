import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ServerNavbar from '../../Components/ServerNavbar';
import ServerSidebar from '../../Components/ServerSidebar';
import { useApexChart } from '../../hooks/useApexChart';

const CPU_COLOR = '#1e90ff';
const RAM_COLOR = '#00ced1';

export default function Metrics({ serverNavbar, sidebar, canUpdate, isMetricsEnabled, isSentinelEnabled, sentinelUrl, toggleUrl, dataUrl }) {
    const [interval, setIntervalValue] = useState(5);
    const [poll, setPoll] = useState(true);
    const pollTimerRef = useRef(null);

    const updateCpuChart = useApexChart('server-cpu', 'CPU %', '%', CPU_COLOR);
    const updateMemoryChart = useApexChart('server-memory', 'Memory (%)', '%', RAM_COLOR);

    async function loadData(currentInterval) {
        try {
            const response = await fetch(`${dataUrl}?interval=${currentInterval}`, {
                headers: { Accept: 'application/json' },
            });
            const result = await response.json();
            if (result.cpu) {
                updateCpuChart(result.cpu, {
                    labels: { formatter: (value) => `${Math.round(value)} %` },
                });
            }
            if (result.memory) {
                updateMemoryChart(result.memory, {
                    min: 0,
                    labels: { formatter: (value) => `${Math.round(value)} %` },
                });
            }
        } catch {
            // Chart simply keeps showing "Loading..." if a fetch fails; next poll will retry.
        }
    }

    useEffect(() => {
        if (!isMetricsEnabled) return undefined;

        loadData(interval);

        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isMetricsEnabled]);

    useEffect(() => {
        if (!isMetricsEnabled) return undefined;

        if (poll || interval <= 10) {
            pollTimerRef.current = setInterval(() => {
                loadData(interval);
                if (interval > 10) {
                    setPoll(false);
                }
            }, 5000);
        }

        return () => {
            if (pollTimerRef.current) {
                clearInterval(pollTimerRef.current);
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [interval, poll, isMetricsEnabled]);

    function changeInterval(e) {
        const value = Number(e.target.value);
        setIntervalValue(value);
        if (value <= 10) {
            setPoll(true);
        }
        loadData(value);
    }

    function toggleMetrics() {
        router.post(toggleUrl, {}, { preserveScroll: true });
    }

    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ServerSidebar sidebar={sidebar} />
                <div className="w-full">
                    <div className="flex items-center gap-2">
                        <h2>Metrics</h2>
                        {canUpdate && isMetricsEnabled && (
                            <button type="button" onClick={toggleMetrics}>
                                Disable Metrics
                            </button>
                        )}
                        {canUpdate && !isMetricsEnabled && isSentinelEnabled && (
                            <button type="button" onClick={toggleMetrics}>
                                Enable Metrics
                            </button>
                        )}
                    </div>
                    <div className="pb-4">Basic metrics for your server.</div>

                    {isMetricsEnabled ? (
                        <div>
                            <label className="flex flex-col gap-1 max-w-xs">
                                Interval
                                <select id="interval" name="interval" value={interval} onChange={changeInterval}>
                                    <option value={5}>5 minutes (live)</option>
                                    <option value={10}>10 minutes (live)</option>
                                    <option value={30}>30 minutes</option>
                                    <option value={60}>1 hour</option>
                                    <option value={720}>12 hours</option>
                                    <option value={10080}>1 week</option>
                                    <option value={43200}>30 days</option>
                                </select>
                            </label>
                            <h4 className="pt-4">CPU Usage</h4>
                            <div id="server-cpu"></div>

                            <h4 className="pt-4">Memory Usage</h4>
                            <div id="server-memory"></div>
                        </div>
                    ) : isSentinelEnabled ? (
                        <div className="p-3 border border-sky-500/30 bg-sky-500/10 text-sm rounded">
                            Metrics are disabled for this server. Click &quot;Enable Metrics&quot; above to start collecting metrics.
                        </div>
                    ) : (
                        <div className="p-3 border border-sky-500/30 bg-sky-500/10 text-sm rounded">
                            Metrics require Sentinel to be enabled. Please{' '}
                            <a className="underline font-semibold" href={sentinelUrl}>
                                enable Sentinel
                            </a>{' '}
                            first.
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
