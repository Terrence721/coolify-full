import { useEffect, useRef } from 'react';

/**
 * Framework-agnostic ApexCharts loader/chart hook, extracted from Server/Metrics.jsx once
 * Project/Shared/Metrics.jsx needed the identical CPU/Memory area-chart setup (same
 * options, same custom tooltip, same lazy-load-the-vendor-script approach) for
 * application/database resources.
 */

function isDarkMode() {
    return document.documentElement.classList.contains('dark');
}

function textColor() {
    return isDarkMode() ? '#ffffff' : '#000000';
}

let apexChartsLoading = null;

export function loadApexCharts() {
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

export function useApexChart(elementId, label, unit, color) {
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
