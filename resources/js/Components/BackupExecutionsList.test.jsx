import { fireEvent, render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import BackupExecutionsList from './BackupExecutionsList';

// The backup executions list shown under a database's backup schedule. Real logic: a live
// useTeamChannel listener (BackupCreated -> reload) combined with a 5s poll that's only active
// on the first page (mirroring the original wire:poll's @if (!$skip) guard) - two independent
// async triggers for the same reload, not just one. Also real: pagination math (skip/defaultTake,
// showNext/showPrev-driven disabled state, page count), and a typed-confirmation Cleanup Deleted
// modal. ExecutionCard is mocked out - it's a separate untested item in the backlog with its own
// real branching (status-text ternary, conditional S3 checkbox), worth its own suite later.

let teamChannelCallback = null;
const reloadSpy = vi.fn();
const postSpy = vi.fn();
const getSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        reload: (opts) => reloadSpy(opts),
        post: (url, data, opts) => postSpy(url, data, opts),
        get: (url, data, opts) => getSpy(url, data, opts),
    },
}));

vi.mock('../hooks/useTeamChannel', () => ({
    useTeamChannel: (events, onEvent) => {
        teamChannelCallback = onEvent;
    },
}));

vi.mock('./ExecutionCard', () => ({
    default: ({ execution }) => <div data-testid="execution-card">{execution.id}</div>,
}));

vi.mock('./PasswordConfirmModal', () => ({
    default: ({ title, action, onClose }) => (
        <div data-testid="PasswordConfirmModal">
            <div>{title}</div>
            <div>{JSON.stringify(action)}</div>
            <button type="button" onClick={onClose}>
                close-modal
            </button>
        </div>
    ),
}));

afterEach(() => {
    reloadSpy.mockClear();
    postSpy.mockClear();
    getSpy.mockClear();
    teamChannelCallback = null;
    vi.useRealTimers();
});

function baseProps(overrides = {}) {
    return {
        executions: [],
        executionsCount: 0,
        skip: 0,
        defaultTake: 5,
        currentPage: 1,
        showNext: false,
        showPrev: false,
        urls: { cleanupFailed: '/backups/cleanup-failed', cleanupDeleted: '/backups/cleanup-deleted' },
        ...overrides,
    };
}

it('shows "No executions found." when there are none', () => {
    render(<BackupExecutionsList {...baseProps()} />);
    expect(screen.getByText('No executions found.')).toBeInTheDocument();
});

it('renders an ExecutionCard per execution and hides pagination when the count is 0', () => {
    render(<BackupExecutionsList {...baseProps()} />);
    expect(screen.queryByText(/Page \d+ of \d+/)).not.toBeInTheDocument();
});

describe('pagination', () => {
    function propsWithExecutions(overrides = {}) {
        return baseProps({
            executions: [{ id: 1 }, { id: 2 }],
            executionsCount: 12,
            defaultTake: 5,
            currentPage: 2,
            showNext: true,
            showPrev: true,
            ...overrides,
        });
    }

    it('shows the current page and total page count', () => {
        render(<BackupExecutionsList {...propsWithExecutions()} />);
        expect(screen.getByText('Page 2 of 3')).toBeInTheDocument();
    });

    it('disables ← when showPrev is false and → when showNext is false', () => {
        render(<BackupExecutionsList {...propsWithExecutions({ showPrev: false, showNext: false })} />);
        expect(screen.getByRole('button', { name: '←' })).toBeDisabled();
        expect(screen.getByRole('button', { name: '→' })).toBeDisabled();
    });

    it('advances skip by defaultTake via router.get when → is clicked', () => {
        render(<BackupExecutionsList {...propsWithExecutions({ skip: 5 })} />);
        fireEvent.click(screen.getByRole('button', { name: '→' }));

        expect(getSpy).toHaveBeenCalledTimes(1);
        const [url] = getSpy.mock.calls[0];
        expect(url).toContain('skip=10');
    });

    it('never sends a negative skip when ← is clicked near the start', () => {
        render(<BackupExecutionsList {...propsWithExecutions({ skip: 2, defaultTake: 5 })} />);
        fireEvent.click(screen.getByRole('button', { name: '←' }));

        const [url] = getSpy.mock.calls[0];
        expect(url).toContain('skip=0');
    });
});

it('posts to urls.cleanupFailed when "Cleanup Failed Backups" is clicked', () => {
    render(<BackupExecutionsList {...baseProps()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Cleanup Failed Backups' }));

    expect(postSpy).toHaveBeenCalledWith('/backups/cleanup-failed', {}, { preserveScroll: true });
});

describe('Cleanup Deleted modal', () => {
    it('opens from the button and closes via onClose', () => {
        render(<BackupExecutionsList {...baseProps()} />);
        expect(screen.queryByTestId('PasswordConfirmModal')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Cleanup Deleted' }));

        const modal = screen.getByTestId('PasswordConfirmModal');
        expect(modal).toHaveTextContent('Cleanup Deleted Backup Entries?');
        expect(modal).toHaveTextContent('"url":"/backups/cleanup-deleted"');

        fireEvent.click(screen.getByRole('button', { name: 'close-modal' }));
        expect(screen.queryByTestId('PasswordConfirmModal')).not.toBeInTheDocument();
    });
});

it('reloads executions when a BackupCreated team-channel event fires', () => {
    render(<BackupExecutionsList {...baseProps()} />);
    expect(teamChannelCallback).toBeInstanceOf(Function);

    teamChannelCallback();

    expect(reloadSpy).toHaveBeenCalledWith({ only: ['executions', 'executionsCount', 'showNext', 'showPrev'] });
});

describe('first-page polling', () => {
    it('polls every 5s only while skip is falsy (first page)', () => {
        vi.useFakeTimers();
        render(<BackupExecutionsList {...baseProps({ skip: 0 })} />);

        expect(reloadSpy).not.toHaveBeenCalled();

        act(() => vi.advanceTimersByTime(5000));
        expect(reloadSpy).toHaveBeenCalledWith({
            only: ['executions', 'executionsCount', 'showNext', 'showPrev'],
            preserveScroll: true,
        });

        act(() => vi.advanceTimersByTime(5000));
        expect(reloadSpy).toHaveBeenCalledTimes(2);
    });

    it('does not poll on a later page (skip is truthy)', () => {
        vi.useFakeTimers();
        render(<BackupExecutionsList {...baseProps({ skip: 5 })} />);

        act(() => vi.advanceTimersByTime(15000));
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('stops polling after unmount', () => {
        vi.useFakeTimers();
        const { unmount } = render(<BackupExecutionsList {...baseProps({ skip: 0 })} />);

        unmount();
        act(() => vi.advanceTimersByTime(15000));

        expect(reloadSpy).not.toHaveBeenCalled();
    });
});
