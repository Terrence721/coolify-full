import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Metrics from './Metrics';

// The application/database metrics tab - a 279-line page branching on resourceType for its
// heading/sidebar, gated by a 3-way availability check (Docker Compose unavailable / Sentinel
// disabled / container not running), and wrapping the exact same interval/poll state machine
// already locked in for Server/Metrics.jsx (grace-tick-then-stop when switching to a static
// range) - this suite mirrors those polling cases for the port, plus the parts unique here:
// the availability gate and the application-vs-database sidebar/heading branching.

const chartUpdateSpies = {};

vi.mock('../../../Components/ApplicationHeading', () => ({
    default: ({ heading }) => <div data-testid="application-heading">{heading?.title}</div>,
}));
vi.mock('../../../Components/DatabaseHeading', () => ({
    default: ({ heading }) => <div data-testid="database-heading">{heading?.title}</div>,
}));
vi.mock('../../../Components/ConfigurationChecker', () => ({ default: () => <div data-testid="configuration-checker" /> }));
vi.mock('../../../hooks/useApexChart', () => ({
    useApexChart: (elementId) => {
        chartUpdateSpies[elementId] = vi.fn();
        return chartUpdateSpies[elementId];
    },
}));

function baseParameters(overrides = {}) {
    return {
        project_uuid: 'proj-1',
        environment_uuid: 'env-1',
        application_uuid: 'app-1',
        database_uuid: 'db-1',
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        resourceType: 'application',
        application: { name: 'my-app' },
        heading: { title: 'my-app' },
        databaseHeading: { title: 'my-db' },
        headingUrls: {},
        configurationChecker: {},
        parameters: baseParameters(),
        isUnavailable: false,
        isMetricsEnabled: true,
        isRunning: true,
        serverMetricsUrl: '/server/srv-1/metrics',
        dataUrl: '/project/proj-1/environment/env-1/application/app-1/metrics/data',
        sidebarFlags: {},
        ...overrides,
    };
}

describe('Project/Shared/Metrics', () => {
    beforeEach(() => {
        for (const key of Object.keys(chartUpdateSpies)) delete chartUpdateSpies[key];
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ cpu: [], memory: [] }) }));
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('renders the ApplicationHeading and ApplicationSidebar for resourceType application', () => {
        render(<Metrics {...baseProps({ resourceType: 'application' })} />);
        expect(screen.getByTestId('application-heading')).toBeInTheDocument();
        expect(screen.queryByTestId('database-heading')).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Advanced' })).toHaveAttribute(
            'href',
            '/project/proj-1/environment/env-1/application/app-1/advanced',
        );
    });

    it('renders the DatabaseHeading and DatabaseSidebar for resourceType database', () => {
        render(<Metrics {...baseProps({ resourceType: 'database', sidebarFlags: { canUpdate: true } })} />);
        expect(screen.getByTestId('database-heading')).toBeInTheDocument();
        expect(screen.queryByTestId('application-heading')).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Import Backup' })).toHaveAttribute(
            'href',
            '/project/proj-1/environment/env-1/database/db-1/import-backup',
        );
    });

    it("hides the Import Backup link when the database sidebar's canUpdate flag is false", () => {
        render(<Metrics {...baseProps({ resourceType: 'database', sidebarFlags: { canUpdate: false } })} />);
        expect(screen.queryByRole('link', { name: 'Import Backup' })).not.toBeInTheDocument();
    });

    it("shows the application sidebar's conditional links only when their flags are set", () => {
        render(
            <Metrics
                {...baseProps({
                    resourceType: 'application',
                    sidebarFlags: { isSwarm: true, isGitBased: true, isDockerImage: false, isDockerCompose: true },
                })}
            />,
        );
        expect(screen.getByRole('link', { name: 'Swarm' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Git Source' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Preview Deployments' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Healthcheck' })).not.toBeInTheDocument();
    });

    it('shows the Docker Compose unavailable message and no charts when isUnavailable is true', () => {
        render(<Metrics {...baseProps({ isUnavailable: true })} />);
        expect(screen.getByText('Metrics are not available for Docker Compose applications yet!')).toBeInTheDocument();
        expect(screen.queryByLabelText('Interval')).not.toBeInTheDocument();
    });

    it('shows the Sentinel-required message with a link when metrics are not enabled', () => {
        render(<Metrics {...baseProps({ isUnavailable: false, isMetricsEnabled: false })} />);
        expect(screen.getByText(/Metrics are only available for servers with Sentinel/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Server Metrics' })).toHaveAttribute('href', '/server/srv-1/metrics');
        expect(screen.queryByLabelText('Interval')).not.toBeInTheDocument();
    });

    it('shows the not-running message when metrics are enabled but the container is not running', () => {
        render(<Metrics {...baseProps({ isMetricsEnabled: true, isRunning: false })} />);
        expect(screen.getByText('Metrics are only available when the container is running!')).toBeInTheDocument();
        expect(screen.queryByLabelText('Interval')).not.toBeInTheDocument();
    });

    it('renders the interval select and both chart containers once every gate passes', () => {
        render(<Metrics {...baseProps()} />);
        expect(screen.getByLabelText('Interval')).toBeInTheDocument();
        expect(document.getElementById('resource-cpu')).toBeInTheDocument();
        expect(document.getElementById('resource-memory')).toBeInTheDocument();
    });

    it('fetches data on mount at the default 5-minute interval and updates both charts', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ cpu: [[1, 2]], memory: [[3, 4]] }) }));
        await act(async () => {
            render(<Metrics {...baseProps()} />);
        });

        expect(global.fetch).toHaveBeenCalledWith(
            '/project/proj-1/environment/env-1/application/app-1/metrics/data?interval=5',
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );
        expect(chartUpdateSpies['resource-cpu']).toHaveBeenCalledWith([[1, 2]], expect.any(Object));
        expect(chartUpdateSpies['resource-memory']).toHaveBeenCalledWith([[3, 4]], expect.any(Object));
    });

    it('does not render MetricsCharts (and never fetches) when the container is not running', async () => {
        await act(async () => {
            render(<Metrics {...baseProps({ isRunning: false })} />);
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

        expect(global.fetch).toHaveBeenCalledWith('/project/proj-1/environment/env-1/application/app-1/metrics/data?interval=30', expect.any(Object));
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

        await act(async () => {
            const select = screen.getByLabelText('Interval');
            select.value = '30';
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });
        expect(global.fetch).toHaveBeenCalledTimes(1);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(5000);
        });
        expect(global.fetch).toHaveBeenCalledTimes(2);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(12000);
        });
        expect(global.fetch).toHaveBeenCalledTimes(2);
    });
});
