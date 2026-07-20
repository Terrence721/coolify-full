import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ServerNavbar from './ServerNavbar';

// Regression coverage for ServerNavbar's actual end-to-end use of useTeamChannel (the hook
// covered in isolation by hooks/useTeamChannel.test.jsx) - this file exercises the real
// consumer: the proxy-status-change toast logic that only fires under specific old-status ->
// new-status transitions, and the guard against re-notifying the same status twice. Noted as a
// natural next test in todo.md's "Frontend component testing" section.

let teamChannelCallback = null;
const reloadSpy = vi.fn();
const postSpy = vi.fn();
const toastSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { flash: {} } }),
    router: {
        reload: (opts) => reloadSpy(opts),
        post: (url) => postSpy(url),
    },
    Link: ({ href, className, children }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

vi.mock('../hooks/useTeamChannel', () => ({
    useTeamChannel: (events, onEvent) => {
        teamChannelCallback = onEvent;
    },
}));

function makeServerNavbar(overrides = {}) {
    return {
        proxyStatus: 'running',
        proxySet: true,
        proxyForceStop: false,
        isSentinelEnabled: false,
        isSentinelLive: false,
        isSwarmWorker: false,
        isSwarm: false,
        isBuildServer: false,
        isFunctional: true,
        canAccessTerminal: false,
        canUpdate: false,
        currentRouteName: 'server.show',
        traefikDashboardAvailable: false,
        serverIp: '10.0.0.1',
        server: { id: 1, name: 'my-server' },
        urls: {
            show: '/server/1',
            proxy: '/server/1/proxy',
            sentinel: '/server/1/sentinel',
            resources: '/server/1/resources',
            command: '/server/1/command',
            securityPatches: '/server/1/security',
            restart: '/server/1/proxy/restart',
            stop: '/server/1/proxy/stop',
            start: '/server/1/proxy/start',
            checkStatus: '/server/1/proxy/check-status',
        },
        ...overrides,
    };
}

// Simulates the broadcast event firing, then router.reload's own onSuccess being invoked with
// the next proxyStatus - matching exactly what ServerNavbar's real useTeamChannel callback does.
function triggerProxyStatusChange(nextStatus) {
    reloadSpy.mockImplementationOnce(({ onSuccess }) => {
        onSuccess({ props: { serverNavbar: { proxyStatus: nextStatus } } });
    });
    act(() => {
        teamChannelCallback();
    });
}

describe('ServerNavbar', () => {
    beforeEach(() => {
        teamChannelCallback = null;
        reloadSpy.mockClear();
        postSpy.mockClear();
        toastSpy.mockClear();
        window.toast = toastSpy;
        window.confirm = vi.fn(() => true);
    });

    it('renders the current proxy status badge', () => {
        render(<ServerNavbar serverNavbar={makeServerNavbar({ proxyStatus: 'running' })} />);
        expect(screen.getByText('Proxy Running')).toBeInTheDocument();
    });

    it('shows a success toast when the proxy transitions from exited to running', () => {
        render(<ServerNavbar serverNavbar={makeServerNavbar({ proxyStatus: 'exited' })} />);

        triggerProxyStatusChange('running');

        expect(toastSpy).toHaveBeenCalledWith('Success', { type: 'success', description: 'Proxy is running.' });
    });

    it('shows an info toast when the proxy transitions from running to exited', () => {
        render(<ServerNavbar serverNavbar={makeServerNavbar({ proxyStatus: 'running' })} />);

        triggerProxyStatusChange('exited');

        expect(toastSpy).toHaveBeenCalledWith('Info', { type: 'info', description: 'Proxy has exited.' });
    });

    it('shows an error toast when the proxy status becomes error, regardless of prior status', () => {
        render(<ServerNavbar serverNavbar={makeServerNavbar({ proxyStatus: 'running' })} />);

        triggerProxyStatusChange('error');

        expect(toastSpy).toHaveBeenCalledWith('Error', { type: 'danger', description: 'Proxy restart failed. Check logs.' });
    });

    it('does not toast when the status is unchanged', () => {
        render(<ServerNavbar serverNavbar={makeServerNavbar({ proxyStatus: 'running' })} />);

        triggerProxyStatusChange('running');

        expect(toastSpy).not.toHaveBeenCalled();
    });

    it('does not re-notify the same transition twice', () => {
        render(<ServerNavbar serverNavbar={makeServerNavbar({ proxyStatus: 'exited' })} />);

        triggerProxyStatusChange('running');
        expect(toastSpy).toHaveBeenCalledTimes(1);

        triggerProxyStatusChange('running');
        expect(toastSpy).toHaveBeenCalledTimes(1);
    });

    it('asks for confirmation before restarting the proxy, and posts to the restart URL when confirmed', () => {
        render(<ServerNavbar serverNavbar={makeServerNavbar({ proxyStatus: 'running' })} />);

        act(() => screen.getByText('Restart Proxy').click());

        expect(window.confirm).toHaveBeenCalled();
        expect(postSpy).toHaveBeenCalledWith('/server/1/proxy/restart');
    });

    it('does not post when the restart confirmation is cancelled', () => {
        window.confirm = vi.fn(() => false);
        render(<ServerNavbar serverNavbar={makeServerNavbar({ proxyStatus: 'running' })} />);

        act(() => screen.getByText('Restart Proxy').click());

        expect(postSpy).not.toHaveBeenCalled();
    });
});
