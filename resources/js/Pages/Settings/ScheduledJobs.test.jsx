import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ScheduledJobs from './ScheduledJobs';

// The scheduled-jobs admin dashboard (301 lines) - a 3-tab view (Failures/Scheduler Runs/Skipped
// Jobs) with real branching per tab: a type-badge-style lookup with a fallback for unrecognized
// types, a 3-way duration ternary (numeric/running/dash), a resource-link fallback chain 4 levels
// deep, a reason-label lookup with a raw-string fallback, and pagination that's only rendered
// once skipTotalCount exceeds skipDefaultTake with server-driven prev/next disabled state.

const routerGet = vi.fn();
vi.mock('@inertiajs/react', () => ({
    router: { get: (url, params, opts) => routerGet(url, params, opts) },
}));

afterEach(() => {
    routerGet.mockClear();
});

function baseProps(overrides = {}) {
    return {
        filterType: 'all',
        filterDate: 'last_24h',
        executions: [],
        managerRuns: [],
        skipLogs: [],
        skipTotalCount: 0,
        skipDefaultTake: 10,
        skipPage: 0,
        skipCurrentPage: 1,
        showSkipPrev: false,
        showSkipNext: false,
        ...overrides,
    };
}

describe('Settings/ScheduledJobs', () => {
    it('defaults to the Failures tab and shows the tab counts', () => {
        render(
            <ScheduledJobs
                {...baseProps({
                    executions: [
                        {
                            id: 1,
                            type: 'backup',
                            resource_name: 'db-1',
                            server_name: 's1',
                            created_at_human: '1m ago',
                            created_at_formatted: 'x',
                            duration_seconds: 5,
                            message: 'ok',
                            status: 'failed',
                        },
                    ],
                    managerRuns: [{ timestamp: 't', message: 'm', duration_ms: 10, dispatched: 1, skipped: 0 }],
                    skipTotalCount: 3,
                })}
            />,
        );
        expect(screen.getByText('Failures (1)')).toBeInTheDocument();
        expect(screen.getByText('Scheduler Runs (1)')).toBeInTheDocument();
        expect(screen.getByText('Skipped Jobs (3)')).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: 'Message' })).toBeInTheDocument();
    });

    it('shows the empty-state message when there are no failures', () => {
        render(<ScheduledJobs {...baseProps()} />);
        expect(screen.getByText('No failures found for the selected filters.')).toBeInTheDocument();
    });

    it('renders the type badge and capitalized label for a known type', () => {
        render(
            <ScheduledJobs
                {...baseProps({
                    executions: [
                        {
                            id: 1,
                            type: 'backup',
                            resource_name: 'db-1',
                            server_name: 's1',
                            created_at_human: '1m ago',
                            created_at_formatted: 'x',
                            duration_seconds: 5,
                            message: 'ok',
                            status: 'failed',
                        },
                    ],
                })}
            />,
        );
        const badge = screen.getByRole('table').querySelector('span');
        expect(badge.textContent).toBe('Backup');
        expect(badge.className).toContain('bg-blue-100');
    });

    it('falls back to no style class for an unrecognized execution type', () => {
        render(
            <ScheduledJobs
                {...baseProps({
                    executions: [
                        {
                            id: 1,
                            type: 'mystery',
                            resource_name: 'db-1',
                            server_name: 's1',
                            created_at_human: '1m ago',
                            created_at_formatted: 'x',
                            duration_seconds: null,
                            message: 'ok',
                            status: 'failed',
                        },
                    ],
                })}
            />,
        );
        const badge = screen.getByText('Mystery');
        expect(badge.className).not.toContain('bg-');
    });

    it('shows the resource_type suffix only when present', () => {
        const { rerender } = render(
            <ScheduledJobs
                {...baseProps({
                    executions: [
                        {
                            id: 1,
                            type: 'task',
                            resource_name: 'my-app',
                            resource_type: 'application',
                            server_name: 's1',
                            created_at_human: '1m ago',
                            created_at_formatted: 'x',
                            duration_seconds: 1,
                            message: 'ok',
                            status: 'failed',
                        },
                    ],
                })}
            />,
        );
        expect(screen.getByText('(application)')).toBeInTheDocument();

        rerender(
            <ScheduledJobs
                {...baseProps({
                    executions: [
                        {
                            id: 1,
                            type: 'task',
                            resource_name: 'my-app',
                            resource_type: null,
                            server_name: 's1',
                            created_at_human: '1m ago',
                            created_at_formatted: 'x',
                            duration_seconds: 1,
                            message: 'ok',
                            status: 'failed',
                        },
                    ],
                })}
            />,
        );
        expect(screen.queryByText('(application)')).not.toBeInTheDocument();
    });

    it('shows numeric duration in seconds, an ellipsis while running with no duration, and a dash otherwise', () => {
        render(
            <ScheduledJobs
                {...baseProps({
                    executions: [
                        {
                            id: 1,
                            type: 'task',
                            resource_name: 'a',
                            server_name: 's',
                            created_at_human: '',
                            created_at_formatted: '',
                            duration_seconds: 12,
                            message: '',
                            status: 'failed',
                        },
                        {
                            id: 2,
                            type: 'task',
                            resource_name: 'b',
                            server_name: 's',
                            created_at_human: '',
                            created_at_formatted: '',
                            duration_seconds: null,
                            message: '',
                            status: 'running',
                        },
                        {
                            id: 3,
                            type: 'task',
                            resource_name: 'c',
                            server_name: 's',
                            created_at_human: '',
                            created_at_formatted: '',
                            duration_seconds: null,
                            message: '',
                            status: 'failed',
                        },
                    ],
                })}
            />,
        );
        expect(screen.getByText('12s')).toBeInTheDocument();
        expect(screen.getByText('…')).toBeInTheDocument();
        expect(screen.getByText('-')).toBeInTheDocument();
    });

    it('reloads with the new type filter and resets skipPage to 0', () => {
        render(<ScheduledJobs {...baseProps({ filterType: 'all', filterDate: 'last_24h', skipPage: 20 })} />);
        fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'backup' } });

        expect(routerGet).toHaveBeenCalledWith(
            '/settings/scheduled-jobs',
            { filterType: 'backup', filterDate: 'last_24h', skipPage: 0 },
            { preserveState: true },
        );
    });

    it('reloads with the new date filter', () => {
        render(<ScheduledJobs {...baseProps({ filterType: 'task', filterDate: 'last_24h' })} />);
        fireEvent.change(screen.getByLabelText('Time Range'), { target: { value: 'last_7d' } });

        expect(routerGet).toHaveBeenCalledWith(
            '/settings/scheduled-jobs',
            { filterType: 'task', filterDate: 'last_7d', skipPage: 0 },
            { preserveState: true },
        );
    });

    it('the Refresh button reloads with the current filters and skipPage unchanged', () => {
        render(<ScheduledJobs {...baseProps({ filterType: 'cleanup', filterDate: 'all', skipPage: 30 })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));

        expect(routerGet).toHaveBeenCalledWith(
            '/settings/scheduled-jobs',
            { filterType: 'cleanup', filterDate: 'all', skipPage: 30 },
            { preserveState: true },
        );
    });

    it('switches to the Scheduler Runs tab and shows its empty state', () => {
        render(<ScheduledJobs {...baseProps()} />);
        fireEvent.click(screen.getByText('Scheduler Runs (0)'));
        expect(screen.getByText('No scheduler run logs found. Logs appear after the ScheduledJobManager runs.')).toBeInTheDocument();
        expect(screen.queryByText('No failures found for the selected filters.')).not.toBeInTheDocument();
    });

    it('renders scheduler-run rows with the duration ternary, dispatched fallback, and skipped styling', () => {
        render(
            <ScheduledJobs
                {...baseProps({
                    managerRuns: [
                        { timestamp: 't1', message: 'run one', duration_ms: 42, dispatched: 3, skipped: 2 },
                        { timestamp: 't2', message: 'run two', duration_ms: null, dispatched: null, skipped: 0 },
                    ],
                })}
            />,
        );
        fireEvent.click(screen.getByText('Scheduler Runs (2)'));

        expect(screen.getByText('42ms')).toBeInTheDocument();
        const warningSkip = screen.getByText('2');
        expect(warningSkip.className).toContain('text-warning');

        const rows = screen.getAllByRole('row');
        const secondDataRow = rows[2];
        // duration_ms null -> '-', dispatched null -> '-' (?? '-'); skipped 0 is NOT nullish,
        // so (run.skipped ?? '-') keeps the literal 0 rather than falling back to '-'.
        expect(within(secondDataRow).getAllByText('-')).toHaveLength(2);
        const skippedCell = within(secondDataRow).getByText('0');
        expect(skippedCell.className).not.toContain('text-warning');
    });

    it('switches to the Skipped Jobs tab and hides pagination when the count fits on one page', () => {
        render(<ScheduledJobs {...baseProps({ skipTotalCount: 5, skipDefaultTake: 10 })} />);
        fireEvent.click(screen.getByText('Skipped Jobs (5)'));
        expect(screen.queryByRole('button', { name: '←' })).not.toBeInTheDocument();
    });

    it('shows pagination with the correct page count and prev/next disabled state when the count exceeds one page', () => {
        render(
            <ScheduledJobs
                {...baseProps({
                    skipTotalCount: 25,
                    skipDefaultTake: 10,
                    skipCurrentPage: 2,
                    showSkipPrev: true,
                    showSkipNext: false,
                })}
            />,
        );
        fireEvent.click(screen.getByText('Skipped Jobs (25)'));

        expect(screen.getByText('Page 2 of 3')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: '←' })).not.toBeDisabled();
        expect(screen.getByRole('button', { name: '→' })).toBeDisabled();
    });

    it('paginates forward by skipDefaultTake and backward with a floor of 0', () => {
        render(
            <ScheduledJobs
                {...baseProps({
                    skipTotalCount: 25,
                    skipDefaultTake: 10,
                    skipPage: 10,
                    showSkipPrev: true,
                    showSkipNext: true,
                })}
            />,
        );
        fireEvent.click(screen.getByText('Skipped Jobs (25)'));

        fireEvent.click(screen.getByRole('button', { name: '→' }));
        expect(routerGet).toHaveBeenLastCalledWith(
            '/settings/scheduled-jobs',
            { filterType: 'all', filterDate: 'last_24h', skipPage: 20 },
            { preserveState: true },
        );

        fireEvent.click(screen.getByRole('button', { name: '←' }));
        expect(routerGet).toHaveBeenLastCalledWith(
            '/settings/scheduled-jobs',
            { filterType: 'all', filterDate: 'last_24h', skipPage: 0 },
            { preserveState: true },
        );
    });

    it('renders a skip row link when link is present, falling all the way back to "Deleted" when nothing else is', () => {
        render(
            <ScheduledJobs
                {...baseProps({
                    skipTotalCount: 4,
                    skipLogs: [
                        { timestamp: 't', type: 'backup', reason: 'server_not_functional', link: '/server/abc', resource_name: 'db-1' },
                        {
                            timestamp: 't',
                            type: 'task',
                            reason: 'resource_deleted',
                            link: null,
                            resource_name: null,
                            context: { task_name: 'nightly-task' },
                        },
                        {
                            timestamp: 't',
                            type: 'task',
                            reason: 'resource_deleted',
                            link: null,
                            resource_name: null,
                            context: { server_name: 'srv-1' },
                        },
                        { timestamp: 't', type: 'task', reason: 'resource_deleted', link: null, resource_name: null, context: {} },
                    ],
                })}
            />,
        );
        fireEvent.click(screen.getByText('Skipped Jobs (4)'));

        expect(screen.getByRole('link', { name: 'db-1' })).toHaveAttribute('href', '/server/abc');
        expect(screen.getByText('nightly-task')).toBeInTheDocument();
        expect(screen.getByText('srv-1')).toBeInTheDocument();
        expect(screen.getByText('Deleted')).toBeInTheDocument();
    });

    it('shows a mapped reason label for a known reason and a humanized fallback for an unknown one', () => {
        render(
            <ScheduledJobs
                {...baseProps({
                    skipTotalCount: 2,
                    skipLogs: [
                        { timestamp: 't', type: 'backup', reason: 'server_not_functional', link: null, resource_name: 'a' },
                        { timestamp: 't', type: 'backup', reason: 'some_unmapped_reason', link: null, resource_name: 'b' },
                    ],
                })}
            />,
        );
        fireEvent.click(screen.getByText('Skipped Jobs (2)'));

        expect(screen.getByText('Server not functional')).toBeInTheDocument();
        expect(screen.getByText('some unmapped_reason')).toBeInTheDocument();
    });

    it('shows the empty-state message when there are no skipped jobs', () => {
        render(<ScheduledJobs {...baseProps({ skipTotalCount: 0, skipLogs: [] })} />);
        fireEvent.click(screen.getByText('Skipped Jobs (0)'));
        expect(screen.getByText('No skipped jobs found. This means all scheduled jobs passed their conditions.')).toBeInTheDocument();
    });
});
