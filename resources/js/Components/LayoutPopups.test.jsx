import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import LayoutPopups from './LayoutPopups';

// Regression coverage for LayoutPopups.jsx - the two dismissible monthly-reminder popups
// (no-notification-channel-enabled, realtime-service-unreachable). Previously only exercised
// indirectly via being mocked out in AppLayout.test.jsx; this suite covers its own real logic
// directly: the monthly re-show rule, the isCloud guard, and the failed-connection-check counter.

let pageProps = {};
const teamChannelCallback = vi.fn();

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: pageProps }),
}));

vi.mock('../hooks/useTeamChannel', () => ({
    useTeamChannel: (events, onEvent) => teamChannelCallback(events, onEvent),
}));

const connectionState = { state: 'connecting' };

vi.mock('../echo', () => ({
    getEcho: () => ({ connector: { pusher: { connection: connectionState } } }),
}));

function baseProps(overrides = {}) {
    return {
        permissions: { isCloud: false },
        currentTeam: { isAnyNotificationEnabled: true },
        echo: { host: 'test-host', key: 'test-key', port: 6001 },
        ...overrides,
    };
}

describe('LayoutPopups', () => {
    beforeEach(() => {
        localStorage.clear();
        connectionState.state = 'connecting';
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('shows the notification popup when no channel is enabled and it has not been dismissed this month', () => {
        pageProps = baseProps({ currentTeam: { isAnyNotificationEnabled: false } });
        render(<LayoutPopups />);

        expect(screen.getByText('No notifications enabled.')).toBeInTheDocument();
    });

    it('does not show the notification popup when a channel is already enabled', () => {
        pageProps = baseProps({ currentTeam: { isAnyNotificationEnabled: true } });
        render(<LayoutPopups />);

        expect(screen.queryByText('No notifications enabled.')).not.toBeInTheDocument();
    });

    it('does not re-show the notification popup once dismissed this month', () => {
        localStorage.setItem('popupNotification', Date.now().toString());
        pageProps = baseProps({ currentTeam: { isAnyNotificationEnabled: false } });
        render(<LayoutPopups />);

        expect(screen.queryByText('No notifications enabled.')).not.toBeInTheDocument();
    });

    it('shows the notification popup again once a new month has started', () => {
        const lastMonth = new Date();
        lastMonth.setMonth(lastMonth.getMonth() - 1);
        localStorage.setItem('popupNotification', lastMonth.getTime().toString());
        pageProps = baseProps({ currentTeam: { isAnyNotificationEnabled: false } });
        render(<LayoutPopups />);

        expect(screen.getByText('No notifications enabled.')).toBeInTheDocument();
    });

    it('dismisses the notification popup and persists the dismissal', () => {
        pageProps = baseProps({ currentTeam: { isAnyNotificationEnabled: false } });
        render(<LayoutPopups />);

        act(() => screen.getByText('Accept and Close').click());

        expect(screen.queryByText('No notifications enabled.')).not.toBeInTheDocument();
        expect(localStorage.getItem('popupNotification')).not.toBeNull();
    });

    it('shows the realtime-unreachable popup after 5 failed connection checks', () => {
        pageProps = baseProps();
        render(<LayoutPopups />);

        act(() => {
            vi.advanceTimersByTime(2000 * 5);
        });

        expect(screen.getByText('Cannot connect to real-time service')).toBeInTheDocument();
    });

    it('does not show the realtime popup if the connection succeeds before 5 checks', () => {
        pageProps = baseProps();
        render(<LayoutPopups />);

        act(() => {
            vi.advanceTimersByTime(2000 * 2);
            connectionState.state = 'connected';
            vi.advanceTimersByTime(2000 * 5);
        });

        expect(screen.queryByText('Cannot connect to real-time service')).not.toBeInTheDocument();
    });

    it('never shows the realtime popup on the isCloud variant, regardless of connection state', () => {
        pageProps = baseProps({ permissions: { isCloud: true } });
        render(<LayoutPopups />);

        act(() => {
            vi.advanceTimersByTime(2000 * 10);
        });

        expect(screen.queryByText('Cannot connect to real-time service')).not.toBeInTheDocument();
    });

    it('dismisses the realtime popup and persists the dismissal', () => {
        pageProps = baseProps();
        render(<LayoutPopups />);

        act(() => {
            vi.advanceTimersByTime(2000 * 5);
        });
        expect(screen.getByText('Cannot connect to real-time service')).toBeInTheDocument();

        act(() => screen.getByText('Acknowledge & Disable This Popup').click());

        expect(screen.queryByText('Cannot connect to real-time service')).not.toBeInTheDocument();
        expect(localStorage.getItem('popupRealtime')).toBe('disabled');
    });
});
