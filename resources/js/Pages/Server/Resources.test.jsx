import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Resources from './Resources';

// Live-verified 2026-07-25 during the Server management smoke test (issue #26): against the real
// throwaway server, the Managed table rendered empty and the Unmanaged table populated with every
// real container visible to it (a side effect of that "server" sharing the host's own docker.sock,
// see issue #56) - confirming this page's own rendering logic works, though Start/Restart/Stop
// weren't clicked live that cycle for safety (they'd have acted on this session's own real
// containers). This suite locks in the previously-untested logic: the Managed status-color mapping,
// the per-container-state action-button set, the containerAction post, the Refresh button, and the
// useTeamChannel-driven reload.

const postSpy = vi.fn();
const reloadSpy = vi.fn();
let teamChannelCallback = null;

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => postSpy(url, data, options),
        reload: (options) => reloadSpy(options),
    },
    Deferred: ({ children }) => children,
}));

vi.mock('../../hooks/useTeamChannel', () => ({
    useTeamChannel: (events, callback) => {
        teamChannelCallback = callback;
    },
}));

vi.mock('../../Components/ServerNavbar', () => ({ default: () => <div data-testid="server-navbar" /> }));

function baseProps(overrides = {}) {
    return {
        serverNavbar: {},
        managedResources: [],
        unmanagedContainers: [],
        containerActionUrl: '/server/srv-uuid/resources/action',
        ...overrides,
    };
}

describe('Server/Resources', () => {
    beforeEach(() => {
        postSpy.mockClear();
        reloadSpy.mockClear();
        teamChannelCallback = null;
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows "No managed resources found." when there are none', () => {
        render(<Resources {...baseProps()} />);
        expect(screen.getByText('No managed resources found.')).toBeInTheDocument();
    });

    it('renders the Managed table with the right status color per category, including an unrecognized-status fallback', () => {
        render(
            <Resources
                {...baseProps({
                    managedResources: [
                        {
                            uuid: 'a',
                            projectName: 'P',
                            environmentName: 'E',
                            name: 'app-running',
                            type: 'application',
                            status: 'Running',
                            statusCategory: 'running',
                            link: '/a',
                        },
                        {
                            uuid: 'b',
                            projectName: 'P',
                            environmentName: 'E',
                            name: 'app-degraded',
                            type: 'application',
                            status: 'Degraded',
                            statusCategory: 'degraded',
                            link: '/b',
                        },
                        {
                            uuid: 'c',
                            projectName: 'P',
                            environmentName: 'E',
                            name: 'app-stopped',
                            type: 'application',
                            status: 'Stopped',
                            statusCategory: 'stopped',
                            link: '/c',
                        },
                        {
                            uuid: 'd',
                            projectName: 'P',
                            environmentName: 'E',
                            name: 'app-weird',
                            type: 'application',
                            status: 'Weird',
                            statusCategory: 'something-unknown',
                            link: '/d',
                        },
                    ],
                })}
            />,
        );

        expect(screen.getByText('Running')).toHaveClass('text-green-500');
        expect(screen.getByText('Degraded')).toHaveClass('text-yellow-500');
        expect(screen.getByText('Stopped')).toHaveClass('text-red-500');
        expect(screen.getByText('Weird')).not.toHaveClass('text-green-500', 'text-yellow-500', 'text-red-500');
        expect(screen.getByRole('link', { name: 'app-running' })).toHaveAttribute('href', '/a');
    });

    it('shows "No unmanaged resources found." when there are none', () => {
        render(<Resources {...baseProps({ unmanagedContainers: [] })} />);
        expect(screen.getByText('No unmanaged resources found.')).toBeInTheDocument();
    });

    it('shows Restart + Stop for a running container, Start for an exited one, and only Stop for a restarting one', () => {
        render(
            <Resources
                {...baseProps({
                    unmanagedContainers: [
                        { id: 'c1', name: 'running-one', image: 'img:1', state: 'running' },
                        { id: 'c2', name: 'exited-one', image: 'img:2', state: 'exited' },
                        { id: 'c3', name: 'restarting-one', image: 'img:3', state: 'restarting' },
                    ],
                })}
            />,
        );

        const rows = screen.getAllByRole('row').slice(1); // drop header row
        expect(rows[0]).toHaveTextContent('Restart');
        expect(rows[0]).toHaveTextContent('Stop');
        expect(rows[0]).not.toHaveTextContent('Start');

        expect(rows[1]).toHaveTextContent('Start');
        expect(rows[1]).not.toHaveTextContent('Restart');

        expect(rows[2]).toHaveTextContent('Stop');
        expect(rows[2]).not.toHaveTextContent('Restart');
        expect(rows[2]).not.toHaveTextContent('Start');
    });

    it('renders no action buttons at all for an unrecognized container state', () => {
        render(<Resources {...baseProps({ unmanagedContainers: [{ id: 'c1', name: 'weird', image: 'img', state: 'created' }] })} />);
        expect(screen.queryByRole('button', { name: 'Start' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Restart' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Stop' })).not.toBeInTheDocument();
    });

    it('posts the right id/action to containerActionUrl when a container action button is clicked', () => {
        render(<Resources {...baseProps({ unmanagedContainers: [{ id: 'c1', name: 'running-one', image: 'img', state: 'running' }] })} />);

        act(() => screen.getByRole('button', { name: 'Restart' }).click());
        expect(postSpy).toHaveBeenCalledWith('/server/srv-uuid/resources/action', { id: 'c1', action: 'restart' }, { preserveScroll: true });

        act(() => screen.getByRole('button', { name: 'Stop' }).click());
        expect(postSpy).toHaveBeenCalledWith('/server/srv-uuid/resources/action', { id: 'c1', action: 'stop' }, { preserveScroll: true });
    });

    it('reloads managedResources/unmanagedContainers when Refresh is clicked', () => {
        render(<Resources {...baseProps()} />);
        act(() => screen.getByRole('button', { name: 'Refresh' }).click());
        expect(reloadSpy).toHaveBeenCalledWith({ only: ['managedResources', 'unmanagedContainers'] });
    });

    it('reloads the same props when a real ApplicationStatusChanged event arrives on the team channel', () => {
        render(<Resources {...baseProps()} />);
        act(() => teamChannelCallback('ApplicationStatusChanged', {}));
        expect(reloadSpy).toHaveBeenCalledWith({ only: ['managedResources', 'unmanagedContainers'] });
    });
});
