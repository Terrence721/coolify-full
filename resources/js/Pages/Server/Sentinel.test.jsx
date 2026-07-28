import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Sentinel from './Sentinel';

// Regression coverage for a real bug found during the 2026-07-28 Server management smoke test
// (issue #26): toggleSentinel()'s onSuccess unconditionally flipped the local isSentinelEnabled
// state on any successful Inertia visit, but the backend's build-server guard rejects the
// enable attempt via a normal back()->with('error', ...) response - a genuinely successful
// Inertia visit from the client's perspective, just carrying an error flash instead of a real
// toggle. The UI incorrectly switched to the full enabled view (Save/Sync/Disable
// Sentinel/Regenerate + the settings form) while the server-side flag stayed false, only
// correcting itself on a manual page reload. Fixed by reading the real, authoritative
// isSentinelEnabled back from the reloaded page props instead of optimistically flipping.

const postSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => postSpy(url, data, options),
    },
}));

vi.mock('../../Components/ServerNavbar', () => ({ default: () => <div data-testid="server-navbar" /> }));
vi.mock('../../Components/ServerSidebar', () => ({ default: () => <div data-testid="server-sidebar" /> }));

function baseProps(overrides = {}) {
    return {
        serverNavbar: {},
        sidebar: {},
        canUpdate: true,
        isDev: false,
        isSentinelEnabled: false,
        isSentinelLive: false,
        isSentinelDebugEnabled: false,
        sentinelToken: '',
        sentinelCustomUrl: '',
        sentinelMetricsRefreshRateSeconds: 5,
        sentinelMetricsHistoryDays: 7,
        sentinelPushIntervalSeconds: 60,
        submitUrl: '/server/srv-uuid/sentinel',
        toggleUrl: '/server/srv-uuid/sentinel/toggle',
        restartUrl: '/server/srv-uuid/sentinel/restart',
        regenerateTokenUrl: '/server/srv-uuid/sentinel/regenerate-token',
        ...overrides,
    };
}

describe('Server/Sentinel', () => {
    beforeEach(() => {
        postSpy.mockClear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows only "Enable Sentinel" and hides the settings form when disabled', () => {
        render(<Sentinel {...baseProps({ isSentinelEnabled: false })} />);
        expect(screen.getByRole('button', { name: 'Enable Sentinel' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Disable Sentinel' })).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Coolify URL')).not.toBeInTheDocument();
    });

    it('shows Save/Sync-or-Restart/Disable Sentinel/Regenerate and the settings form when enabled', () => {
        render(<Sentinel {...baseProps({ isSentinelEnabled: true, isSentinelLive: true })} />);
        expect(screen.getByRole('button', { name: 'Save' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Restart' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Disable Sentinel' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Regenerate' })).toBeInTheDocument();
        expect(screen.getByLabelText('Coolify URL')).toBeInTheDocument();
    });

    it('labels the restart button "Sync" instead of "Restart" when Sentinel is out of sync, and shows the warning', () => {
        render(<Sentinel {...baseProps({ isSentinelEnabled: true, isSentinelLive: false })} />);
        expect(screen.getByRole('button', { name: 'Sync' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Restart' })).not.toBeInTheDocument();
        expect(screen.getByText('Out of Sync')).toBeInTheDocument();
    });

    it('regression: stays on the disabled view when the toggle is rejected server-side (e.g. build-server guard), instead of optimistically flipping to enabled', () => {
        render(<Sentinel {...baseProps({ isSentinelEnabled: false })} />);

        act(() => screen.getByRole('button', { name: 'Enable Sentinel' }).click());
        // Simulate the real controller's back()->with('error', ...) response: a successful
        // Inertia visit whose fresh props still say the server-side flag never changed.
        const { onSuccess } = postSpy.mock.calls[0][2];
        act(() => onSuccess({ props: { isSentinelEnabled: false } }));

        expect(screen.getByRole('button', { name: 'Enable Sentinel' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Disable Sentinel' })).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Coolify URL')).not.toBeInTheDocument();
    });

    it('switches to the enabled view when the toggle genuinely succeeds', () => {
        render(<Sentinel {...baseProps({ isSentinelEnabled: false })} />);

        act(() => screen.getByRole('button', { name: 'Enable Sentinel' }).click());
        const { onSuccess } = postSpy.mock.calls[0][2];
        act(() => onSuccess({ props: { isSentinelEnabled: true } }));

        expect(screen.getByRole('button', { name: 'Disable Sentinel' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Enable Sentinel' })).not.toBeInTheDocument();
    });

    it('switches back to the disabled view when disabling genuinely succeeds', () => {
        render(<Sentinel {...baseProps({ isSentinelEnabled: true, isSentinelLive: true })} />);

        act(() => screen.getByRole('button', { name: 'Disable Sentinel' }).click());
        const { onSuccess } = postSpy.mock.calls[0][2];
        act(() => onSuccess({ props: { isSentinelEnabled: false } }));

        expect(screen.getByRole('button', { name: 'Enable Sentinel' })).toBeInTheDocument();
    });

    it('calls router.post(restartUrl) when Restart/Sync is clicked', () => {
        render(<Sentinel {...baseProps({ isSentinelEnabled: true, isSentinelLive: true })} />);
        act(() => screen.getByRole('button', { name: 'Restart' }).click());

        expect(postSpy).toHaveBeenCalledWith('/server/srv-uuid/sentinel/restart', {}, { preserveScroll: true });
    });

    it('calls router.post(regenerateTokenUrl) when Regenerate is clicked', () => {
        render(<Sentinel {...baseProps({ isSentinelEnabled: true })} />);
        act(() => screen.getByRole('button', { name: 'Regenerate' }).click());

        expect(postSpy).toHaveBeenCalledWith('/server/srv-uuid/sentinel/regenerate-token', {}, { preserveScroll: true });
    });

    it('shows the debug checkbox only in dev mode while enabled, and posts on toggle', () => {
        const { rerender } = render(<Sentinel {...baseProps({ isSentinelEnabled: true, isDev: false })} />);
        expect(screen.queryByLabelText('Enable Sentinel (with debug)')).not.toBeInTheDocument();

        rerender(<Sentinel {...baseProps({ isSentinelEnabled: true, isDev: true })} />);
        act(() => screen.getByLabelText('Enable Sentinel (with debug)').click());

        expect(postSpy).toHaveBeenCalledWith(
            '/server/srv-uuid/sentinel',
            expect.objectContaining({ isSentinelDebugEnabled: true }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('submits the full settings form on Save', () => {
        render(
            <Sentinel
                {...baseProps({
                    isSentinelEnabled: true,
                    sentinelToken: 'tok-123',
                    sentinelCustomUrl: 'https://coolify.example.com',
                    sentinelMetricsRefreshRateSeconds: 10,
                    sentinelMetricsHistoryDays: 14,
                    sentinelPushIntervalSeconds: 30,
                })}
            />,
        );
        act(() => screen.getByRole('button', { name: 'Save' }).click());

        expect(postSpy).toHaveBeenCalledWith(
            '/server/srv-uuid/sentinel',
            {
                sentinelToken: 'tok-123',
                sentinelCustomUrl: 'https://coolify.example.com',
                sentinelMetricsRefreshRateSeconds: 10,
                sentinelMetricsHistoryDays: 14,
                sentinelPushIntervalSeconds: 30,
                isSentinelDebugEnabled: false,
            },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('renders field-level errors from onError and re-enables Save via onFinish', () => {
        render(<Sentinel {...baseProps({ isSentinelEnabled: true, sentinelToken: 'tok-123', sentinelCustomUrl: 'https://coolify.example.com' })} />);
        act(() => screen.getByRole('button', { name: 'Save' }).click());

        const { onError, onFinish } = postSpy.mock.calls[0][2];
        act(() => {
            onError({ sentinelCustomUrl: 'The sentinel custom url must be a valid URL.' });
            onFinish();
        });

        expect(screen.getByText('The sentinel custom url must be a valid URL.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save' })).not.toBeDisabled();
    });

    it('hides all mutating controls when canUpdate is false', () => {
        render(<Sentinel {...baseProps({ isSentinelEnabled: true, canUpdate: false })} />);
        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Restart' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Disable Sentinel' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Regenerate' })).not.toBeInTheDocument();
    });
});
