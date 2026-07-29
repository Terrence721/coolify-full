import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ActivityLog from './ActivityLog';

// Previously entirely untested despite being the shared log-streaming component behind every
// flash-triggered log slide-over across the app (Sentinel, LogDrains, Security/Patches,
// CloudflareTunnel, DockerCleanup, ServerNavbar's proxy log, and more). Written while reviewing
// the react-hooks/set-state-in-effect ESLint finding at line 20 (issue #33) - the effect's
// !activityId branch synchronously reset output/isPolling, but neither value is ever rendered
// while !activityId (the component early-returns to the "Waiting..." message/null before
// reaching the JSX that reads them), so the reset was dead code. Removed it; this suite locks in
// the component's real, previously-unverified polling behavior around that change.

const originalFetch = global.fetch;

function jsonResponse(body) {
    return Promise.resolve({ json: () => Promise.resolve(body) });
}

describe('ActivityLog', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        global.fetch = originalFetch;
        vi.restoreAllMocks();
    });

    it('shows the waiting message when there is no activityId (showWaiting defaults true)', () => {
        render(<ActivityLog activityId={null} />);
        expect(screen.getByText('Waiting for the process to start...')).toBeInTheDocument();
    });

    it('renders nothing when there is no activityId and showWaiting is false', () => {
        const { container } = render(<ActivityLog activityId={null} showWaiting={false} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('does not fetch when there is no activityId', async () => {
        global.fetch = vi.fn();
        render(<ActivityLog activityId={null} />);
        await act(async () => {
            await vi.runOnlyPendingTimersAsync();
        });
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('polls immediately, shows "(running...)" while polling, and displays the fetched output', async () => {
        global.fetch = vi.fn(() => jsonResponse({ found: true, output: 'line one', exitCode: null }));
        render(<ActivityLog activityId="act-1" header="Logs" />);

        await act(async () => {
            await vi.runOnlyPendingTimersAsync();
        });

        expect(global.fetch).toHaveBeenCalledWith('/activity/act-1', { headers: { Accept: 'application/json' } });
        expect(screen.getByText('(running...)')).toBeInTheDocument();
        expect(screen.getByText('line one')).toBeInTheDocument();
    });

    it('continues polling every 1s while exitCode is null', async () => {
        global.fetch = vi.fn(() => jsonResponse({ found: true, output: 'still going', exitCode: null }));
        render(<ActivityLog activityId="act-1" />);

        await act(async () => {
            await vi.runOnlyPendingTimersAsync();
        });
        expect(global.fetch).toHaveBeenCalledTimes(1);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(1000);
        });
        expect(global.fetch).toHaveBeenCalledTimes(2);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(1000);
        });
        expect(global.fetch).toHaveBeenCalledTimes(3);
    });

    it('stops polling and calls onFinished once a real exitCode is returned', async () => {
        global.fetch = vi.fn(() => jsonResponse({ found: true, output: 'done', exitCode: 0 }));
        const onFinished = vi.fn();
        render(<ActivityLog activityId="act-1" onFinished={onFinished} />);

        await act(async () => {
            await vi.runOnlyPendingTimersAsync();
        });

        expect(onFinished).toHaveBeenCalledWith(0);
        expect(screen.queryByText('(running...)')).not.toBeInTheDocument();

        global.fetch.mockClear();
        await act(async () => {
            await vi.advanceTimersByTimeAsync(3000);
        });
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('resets and starts fresh polling when activityId changes to a new one', async () => {
        global.fetch = vi
            .fn()
            .mockImplementationOnce(() => jsonResponse({ found: true, output: 'first activity output', exitCode: 0 }))
            .mockImplementationOnce(() => jsonResponse({ found: true, output: 'second activity output', exitCode: null }));

        const { rerender } = render(<ActivityLog activityId="act-1" />);
        await act(async () => {
            await vi.runOnlyPendingTimersAsync();
        });
        expect(screen.getByText('first activity output')).toBeInTheDocument();

        rerender(<ActivityLog activityId="act-2" />);
        await act(async () => {
            await vi.runOnlyPendingTimersAsync();
        });

        expect(global.fetch).toHaveBeenLastCalledWith('/activity/act-2', { headers: { Accept: 'application/json' } });
        expect(screen.getByText('second activity output')).toBeInTheDocument();
    });

    it('stops polling on unmount', async () => {
        global.fetch = vi.fn(() => jsonResponse({ found: true, output: 'x', exitCode: null }));
        const { unmount } = render(<ActivityLog activityId="act-1" />);

        await act(async () => {
            await vi.runOnlyPendingTimersAsync();
        });
        expect(global.fetch).toHaveBeenCalledTimes(1);

        unmount();
        await act(async () => {
            await vi.advanceTimersByTimeAsync(5000);
        });
        expect(global.fetch).toHaveBeenCalledTimes(1);
    });
});
