import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ServerNavbar from '../../Components/ServerNavbar';
import ServerSidebar from '../../Components/ServerSidebar';

const CPU_COLOR = '#1e90ff';
const RAM_COLOR = '#00ced1';

function isDarkMode() {
    return document.documentElement.classList.contains('dark');
}

function textColor() {
    return isDarkMode() ? '#ffffff' : '#000000';
}

let apexChartsLoading = null;

function loadApexCharts() {
    if (window.ApexCharts) {
        return Promise.resolve();
    }
    if (apexChartsLoading) {
        return apexChartsLoading;
    }
    apexChartsLoading = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = '/js/apexcharts.js';
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Failed to load ApexCharts'));
        document.head.appendChild(script);
    });

    return apexChartsLoading;
}

function tooltipFormatter(label, unit) {
    return function ({ series, seriesIndex, dataPointIndex, w }) {
        const value = series[seriesIndex][dataPointIndex];
        const timestamp = w.globals.seriesX[seriesIndex][dataPointIndex];
        const date = new Date(timestamp);
        const timeString =
            String(date.getUTCHours()).padStart(2, '0') +
            ':' +
            String(date.getUTCMinutes()).padStart(2, '0') +
            ':' +
            String(date.getUTCSeconds()).padStart(2, '0') +
            ', ' +
            date.getUTCFullYear() +
            '-' +
            String(date.getUTCMonth() + 1).padStart(2, '0') +
            '-' +
            String(date.getUTCDate()).padStart(2, '0');

        return (
            '<div class="apexcharts-tooltip-custom">' +
            `<div class="apexcharts-tooltip-custom-value">${label}: <span class="apexcharts-tooltip-value-bold">${value}${unit}</span></div>` +
            `<div class="apexcharts-tooltip-custom-title">${timeString}</div>` +
            '</div>'
        );
    };
}

function baseChartOptions(id, label, unit, color) {
    return {
        stroke: { curve: 'straight', width: 2 },
        chart: {
            height: '150px',
            id,
            type: 'area',
            toolbar: {
                show: true,
                tools: { download: false, selection: false, zoom: true, zoomin: false, zoomout: false, pan: false, reset: true },
            },
            animations: { enabled: true },
        },
        fill: { type: 'gradient' },
        dataLabels: { enabled: false },
        grid: { show: true, borderColor: '' },
        colors: [color],
        xaxis: { type: 'datetime' },
        series: [{ name: label, data: [] }],
        noData: { text: 'Loading...', style: { color: textColor() } },
        tooltip: { enabled: true, marker: { show: false }, custom: tooltipFormatter(label, unit) },
        legend: { show: false },
    };
}

function useApexChart(elementId, label, unit, color) {
    const chartRef = useRef(null);

    useEffect(() => {
        let cancelled = false;

        loadApexCharts().then(() => {
            if (cancelled) return;
            const el = document.getElementById(elementId);
            if (!el) return;
            const chart = new window.ApexCharts(el, baseChartOptions(elementId, label, unit, color));
            chart.render();
            chartRef.current = chart;
        });

        return () => {
            cancelled = true;
            chartRef.current?.destroy();
            chartRef.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [elementId]);

    function update(seriesData, yaxisOptions = {}) {
        chartRef.current?.updateOptions({
            series: [{ data: seriesData }],
            colors: [color],
            xaxis: { type: 'datetime', labels: { show: true, style: { colors: textColor() } } },
            yaxis: { show: true, labels: { show: true, style: { colors: textColor() } }, ...yaxisOptions },
            noData: { text: 'Loading...', style: { color: textColor() } },
        });
    }

    return update;
}

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
                                <select value={interval} onChange={changeInterval}>
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
                            Metrics are disabled for this server. Click "Enable Metrics" above to start collecting metrics.
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
