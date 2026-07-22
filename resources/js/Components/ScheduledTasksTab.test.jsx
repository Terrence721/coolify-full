import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ScheduledTasksTab from './ScheduledTasksTab';

// Covers the list/detail branch on the `task` prop, the Add-task form's container default, the
// canUpdate gating across every mutating control, the Execute-Now/isResourceRunning gate, the
// delete confirmation (withPassword: false, unlike most PasswordConfirmModal call sites), the
// execution-log pagination math (LOGS_PER_PAGE), and the poll-interval speedup (5s -> 1s) that
// kicks in only while the selected execution is still running - previously untested.

let teamChannelCallback = null;
const reloadSpy = vi.fn();
const postSpy = vi.fn((url, data, options) => options?.onSuccess?.());
const patchSpy = vi.fn();
const deleteSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        reload: (opts) => reloadSpy(opts),
        post: (url, data, options) => postSpy(url, data, options),
        patch: (url, data, options) => patchSpy(url, data, options),
        delete: (url, options) => deleteSpy(url, options),
    },
}));

vi.mock('../hooks/useTeamChannel', () => ({
    useTeamChannel: (events, onEvent) => {
        teamChannelCallback = onEvent;
    },
}));

function typeInto(element, value) {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

function baseTask(overrides = {}) {
    return { name: 'nightly-backup', command: 'php artisan backup', frequency: 'daily', container: '', timeout: 300, enabled: true, ...overrides };
}

function baseUrls(overrides = {}) {
    return { store: '/tasks/store', update: '/tasks/1', execute: '/tasks/1/execute', toggle: '/tasks/1/toggle', destroy: '/tasks/1', ...overrides };
}

beforeEach(() => {
    reloadSpy.mockClear();
    postSpy.mockClear();
    patchSpy.mockClear();
    deleteSpy.mockClear();
    teamChannelCallback = null;
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
});

describe('ScheduledTasksTab - list view', () => {
    it('shows the empty state when there are no tasks', () => {
        render(<ScheduledTasksTab tasks={[]} containerNames={[]} taskUrls={baseUrls()} canUpdate={true} />);
        expect(screen.getByText('No scheduled tasks configured.')).toBeInTheDocument();
    });

    it('hides the + Add button when canUpdate is false', () => {
        render(<ScheduledTasksTab tasks={[]} containerNames={[]} taskUrls={baseUrls()} canUpdate={false} />);
        expect(screen.queryByRole('button', { name: '+ Add' })).not.toBeInTheDocument();
    });

    it('defaults the Add-task container to the first containerName and submits via router.post', () => {
        render(<ScheduledTasksTab tasks={[]} containerNames={['php', 'mysql']} taskUrls={baseUrls()} canUpdate={true} />);

        act(() => screen.getByRole('button', { name: '+ Add' }).click());
        expect(screen.getByRole('option', { name: 'php', selected: true })).toBeInTheDocument();

        act(() => typeInto(screen.getByPlaceholderText('Run cron'), 'My Task'));
        act(() => typeInto(screen.getByPlaceholderText('php artisan schedule:run'), 'php artisan backup:run'));
        act(() => typeInto(screen.getByPlaceholderText('0 0 * * * or daily'), 'daily'));
        act(() => screen.getByRole('button', { name: 'Save' }).click());

        expect(postSpy).toHaveBeenCalledWith(
            '/tasks/store',
            expect.objectContaining({ name: 'My Task', container: 'php' }),
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(screen.queryByText('New Scheduled Task')).not.toBeInTheDocument();
    });
});

describe('ScheduledTasksTab - detail view', () => {
    it('disables every mutating control and form field when canUpdate is false', () => {
        render(<ScheduledTasksTab task={baseTask()} executions={[]} isResourceRunning={true} taskUrls={baseUrls()} canUpdate={false} />);

        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Execute Now' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Disable Task' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument();
        expect(screen.getByDisplayValue('nightly-backup')).toBeDisabled();
    });

    it('only shows Execute Now when the resource is actually running', () => {
        const { rerender } = render(
            <ScheduledTasksTab task={baseTask()} executions={[]} isResourceRunning={false} taskUrls={baseUrls()} canUpdate={true} />,
        );
        expect(screen.queryByRole('button', { name: 'Execute Now' })).not.toBeInTheDocument();

        rerender(<ScheduledTasksTab task={baseTask()} executions={[]} isResourceRunning={true} taskUrls={baseUrls()} canUpdate={true} />);
        expect(screen.getByRole('button', { name: 'Execute Now' })).toBeInTheDocument();
    });

    it("labels the toggle button by the task's current enabled state", () => {
        const { rerender } = render(
            <ScheduledTasksTab task={baseTask({ enabled: true })} executions={[]} isResourceRunning={false} taskUrls={baseUrls()} canUpdate={true} />,
        );
        expect(screen.getByRole('button', { name: 'Disable Task' })).toBeInTheDocument();

        rerender(
            <ScheduledTasksTab
                task={baseTask({ enabled: false })}
                executions={[]}
                isResourceRunning={false}
                taskUrls={baseUrls()}
                canUpdate={true}
            />,
        );
        expect(screen.getByRole('button', { name: 'Enable Task' })).toBeInTheDocument();
    });

    it('submits the edited form fields via router.patch, keeping the current enabled state', () => {
        render(
            <ScheduledTasksTab task={baseTask({ enabled: true })} executions={[]} isResourceRunning={false} taskUrls={baseUrls()} canUpdate={true} />,
        );

        act(() => typeInto(screen.getByDisplayValue('nightly-backup'), 'renamed-backup'));
        act(() => screen.getByRole('button', { name: 'Save' }).click());

        expect(patchSpy).toHaveBeenCalledWith(
            '/tasks/1',
            expect.objectContaining({ name: 'renamed-backup', enabled: true }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('deletes via a password-less, typed-name-confirmed PasswordConfirmModal', () => {
        render(
            <ScheduledTasksTab
                task={baseTask({ name: 'critical-job' })}
                executions={[]}
                isResourceRunning={false}
                taskUrls={baseUrls()}
                canUpdate={true}
            />,
        );

        act(() => screen.getByRole('button', { name: 'Delete' }).click());
        expect(screen.getByText('Confirm Scheduled Task Deletion?')).toBeInTheDocument();
        expect(screen.queryByLabelText('Password')).not.toBeInTheDocument();

        const confirmButton = screen.getByRole('button', { name: 'Confirm' });
        expect(confirmButton).toBeDisabled();

        act(() => typeInto(screen.getByPlaceholderText('critical-job'), 'critical-job'));
        act(() => confirmButton.click());

        expect(deleteSpy).toHaveBeenCalledWith('/tasks/1', expect.objectContaining({ preserveScroll: true }));
    });

    it('shows a placeholder message for a running execution with no output yet', () => {
        render(
            <ScheduledTasksTab
                task={baseTask()}
                executions={[{ id: 1, status: 'running', createdAt: 'now', message: null }]}
                isResourceRunning={true}
                taskUrls={baseUrls()}
                canUpdate={true}
            />,
        );

        act(() => screen.getByText('In Progress').click());
        expect(screen.getByText('Waiting for task output...')).toBeInTheDocument();
    });

    it('paginates long execution output, 100 lines per Load More click, with a working Load All', () => {
        const longMessage = Array.from({ length: 250 }, (_, i) => `line ${i}`).join('\n');
        render(
            <ScheduledTasksTab
                task={baseTask()}
                executions={[
                    {
                        id: 1,
                        status: 'success',
                        createdAt: 'now',
                        finishedAt: 'later',
                        duration: '1s',
                        finishedAgo: 'a moment ago',
                        message: longMessage,
                    },
                ]}
                isResourceRunning={false}
                taskUrls={baseUrls()}
                canUpdate={true}
            />,
        );

        act(() => screen.getByText('Success').click());
        expect(screen.getByText('line 0', { exact: false })).toBeInTheDocument();
        expect(screen.queryByText('line 249', { exact: false })).not.toBeInTheDocument();

        act(() => screen.getByRole('button', { name: 'Load All' }).click());
        expect(screen.getByText(/line 249/)).toBeInTheDocument();
    });

    it('only shows a download link when the execution provides one', () => {
        const { rerender } = render(
            <ScheduledTasksTab
                task={baseTask()}
                executions={[{ id: 1, status: 'success', createdAt: 'now', message: 'ok' }]}
                isResourceRunning={false}
                taskUrls={baseUrls()}
                canUpdate={true}
            />,
        );
        expect(screen.queryByRole('link', { name: 'Download Logs' })).not.toBeInTheDocument();

        rerender(
            <ScheduledTasksTab
                task={baseTask()}
                executions={[{ id: 1, status: 'success', createdAt: 'now', message: 'ok', downloadUrl: '/logs/1' }]}
                isResourceRunning={false}
                taskUrls={baseUrls()}
                canUpdate={true}
            />,
        );
        expect(screen.getByRole('link', { name: 'Download Logs' })).toHaveAttribute('href', '/logs/1');
    });

    it('polls every 5s normally, but speeds up to every 1s while the selected execution is running', () => {
        vi.useFakeTimers();
        render(
            <ScheduledTasksTab
                task={baseTask()}
                executions={[{ id: 1, status: 'running', createdAt: 'now', message: null }]}
                isResourceRunning={true}
                taskUrls={baseUrls()}
                canUpdate={true}
            />,
        );

        act(() => vi.advanceTimersByTime(5000));
        expect(reloadSpy).toHaveBeenCalledTimes(1);

        act(() => screen.getByText('In Progress').click());
        reloadSpy.mockClear();

        act(() => vi.advanceTimersByTime(1000));
        expect(reloadSpy).toHaveBeenCalledTimes(1);
    });

    it('reloads just the executions prop when a ScheduledTaskDone team event fires', () => {
        render(<ScheduledTasksTab task={baseTask()} executions={[]} isResourceRunning={false} taskUrls={baseUrls()} canUpdate={true} />);

        expect(teamChannelCallback).toBeInstanceOf(Function);
        act(() => teamChannelCallback());

        expect(reloadSpy).toHaveBeenCalledWith({ only: ['executions'], preserveScroll: true });
    });
});
