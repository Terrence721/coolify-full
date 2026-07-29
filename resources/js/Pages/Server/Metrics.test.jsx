import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Metrics from './Metrics';

// The /server/{uuid}/metrics page, live-verified end-to-end during the 2026-07-28 Server
// management smoke test (issue #26): Enable Metrics produced a real "Metrics enabled. Starting
// Sentinel." toast, both CPU and Memory charts rendered genuine SVG content on a cold reload,
// switching to a static range stopped live polling (with exactly one "grace tick" ~5s after the
// switch before settling - confirmed via real network traces: 1 request right after switching,
// 2 total after waiting 12s, no further growth), and Disable Metrics correctly reverted the
// disabled-state message. This suite locks in that grace-tick polling logic plus the previously
// untested three-way conditional render (metrics enabled / sentinel-enabled-but-metrics-off /
// sentinel disabled), the canUpdate button gates, and the interval-change wiring.

const postSpy = vi.fn();
const chartUpdateSpies = {};

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => postSpy(url, data, options),
    },
}));

vi.mock('../../Components/ServerNavbar', () => ({ default: () => <div data-testid="server-navbar" /> }));
vi.mock('../../Components/ServerSidebar', () => ({ default: () => <div data-testid="server-sidebar" /> }));
vi.mock('../../hooks/useApexChart', () => ({
    useApexChart: (elementId) => {
        chartUpdateSpies[elementId] = vi.fn();
        return chartUpdateSpies[elementId];
    },
}));

function baseProps(overrides = {}) {
    return {
        serverNavbar: {},
        sidebar: {},
        canUpdate: true,
        isMetricsEnabled: true,
        isSentinelEnabled: true,
        sentinelUrl: '/server/srv-uuid/sentinel',
        toggleUrl: '/server/srv-uuid/metrics/toggle',
        dataUrl: '/server/srv-uuid/metrics/data',
        ...overrides,
    };
}

describe('Server/Metrics', () => {
    beforeEach(() => {
        postSpy.mockClear();
        for (const key of Object.keys(chartUpdateSpies)) delete chartUpdateSpies[key];
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ cpu: [], memory: [] }) }));
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('shows the "requires Sentinel" message when Sentinel is disabled', () => {
        render(<Metrics {...baseProps({ isMetricsEnabled: false, isSentinelEnabled: false })} />);
        expect(screen.getByText(/Metrics require Sentinel to be enabled/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'enable Sentinel' })).toHaveAttribute('href', '/server/srv-uuid/sentinel');
        expect(screen.queryByRole('button', { name: 'Enable Metrics' })).not.toBeInTheDocument();
    });

    it('shows the "metrics disabled" message and an Enable button when Sentinel is on but metrics are off', () => {
        render(<Metrics {...baseProps({ isMetricsEnabled: false, isSentinelEnabled: true, canUpdate: true })} />);
        expect(screen.getByText(/Metrics are disabled for this server/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Enable Metrics' })).toBeInTheDocument();
    });

    it('hides the Enable Metrics button when canUpdate is false', () => {
        render(<Metrics {...baseProps({ isMetricsEnabled: false, isSentinelEnabled: true, canUpdate: false })} />);
        expect(screen.queryByRole('button', { name: 'Enable Metrics' })).not.toBeInTheDocument();
    });

    it('renders the interval select and chart containers, with a Disable button, when metrics are enabled', () => {
        render(<Metrics {...baseProps({ isMetricsEnabled: true, canUpdate: true })} />);
        expect(screen.getByRole('button', { name: 'Disable Metrics' })).toBeInTheDocument();
        expect(screen.getByLabelText('Interval')).toBeInTheDocument();
        expect(document.getElementById('server-cpu')).toBeInTheDocument();
        expect(document.getElementById('server-memory')).toBeInTheDocument();
    });

    it('hides the Disable Metrics button when canUpdate is false', () => {
        render(<Metrics {...baseProps({ isMetricsEnabled: true, canUpdate: false })} />);
        expect(screen.queryByRole('button', { name: 'Disable Metrics' })).not.toBeInTheDocument();
    });

    it('calls toggleUrl via router.post when Disable/Enable Metrics is clicked', () => {
        render(<Metrics {...baseProps({ isMetricsEnabled: true })} />);
        act(() => screen.getByRole('button', { name: 'Disable Metrics' }).click());
        expect(postSpy).toHaveBeenCalledWith('/server/srv-uuid/metrics/toggle', {}, { preserveScroll: true });
    });

    it('fetches data on mount and updates both charts from the response', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ cpu: [[1, 2]], memory: [[3, 4]] }) }));
        await act(async () => {
            render(<Metrics {...baseProps({ dataUrl: '/server/srv-uuid/metrics/data' })} />);
        });

        expect(global.fetch).toHaveBeenCalledWith(
            '/server/srv-uuid/metrics/data?interval=5',
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );
        expect(chartUpdateSpies['server-cpu']).toHaveBeenCalledWith([[1, 2]], expect.any(Object));
        expect(chartUpdateSpies['server-memory']).toHaveBeenCalledWith([[3, 4]], expect.any(Object));
    });

    it('does not fetch on mount when metrics are disabled', async () => {
        await act(async () => {
            render(<Metrics {...baseProps({ isMetricsEnabled: false, isSentinelEnabled: true })} />);
        });
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('changing the interval immediately reloads data with the new value', async () => {
        await act(async () => {
            render(<Metrics {...baseProps()} />);
        });
        global.fetch.mockClear();

        await act(async () => {
            screen.getByLabelText('Interval').value = '30';
            screen.getByLabelText('Interval').dispatchEvent(new Event('change', { bubbles: true }));
        });

        expect(global.fetch).toHaveBeenCalledWith('/server/srv-uuid/metrics/data?interval=30', expect.any(Object));
    });

    it('keeps polling every 5s indefinitely while on a live interval (<=10)', async () => {
        vi.useFakeTimers();
        render(<Metrics {...baseProps()} />);
        await act(async () => {
            await vi.runOnlyPendingTimersAsync();
        });
        global.fetch.mockClear();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(5000);
        });
        expect(global.fetch).toHaveBeenCalledTimes(1);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(5000);
        });
        expect(global.fetch).toHaveBeenCalledTimes(2);
    });

    it('polls exactly one grace tick after switching to a static interval (>10), then stops', async () => {
        vi.useFakeTimers();
        render(<Metrics {...baseProps()} />);
        await act(async () => {
            await vi.runOnlyPendingTimersAsync();
        });
        global.fetch.mockClear();

        // Switch to a static 30-minute range - fires one immediate load.
        await act(async () => {
            const select = screen.getByLabelText('Interval');
            select.value = '30';
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });
        expect(global.fetch).toHaveBeenCalledTimes(1);

        // One grace tick ~5s later.
        await act(async () => {
            await vi.advanceTimersByTimeAsync(5000);
        });
        expect(global.fetch).toHaveBeenCalledTimes(2);

        // No further growth after that - polling has stopped.
        await act(async () => {
            await vi.advanceTimersByTimeAsync(12000);
        });
        expect(global.fetch).toHaveBeenCalledTimes(2);
    });
});
