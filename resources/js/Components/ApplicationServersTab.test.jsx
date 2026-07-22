import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ApplicationServersTab from './ApplicationServersTab';

// Covers the primary-vs-additional-server action gating (Deploy/Stop only appear under the
// right combination of hasAdditional/canUpdate/running-status), the "Add another server" picker's
// three-way branch (persistent-storage warning vs. available-network tiles vs. empty state), and
// the remove-from-server confirmation payload - previously untested.

const postSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => postSpy(url, data, options),
    },
}));

vi.mock('./PasswordConfirmModal', () => ({
    default: ({ title, action, confirmationLabel, onClose }) => (
        <div role="dialog" aria-label={title}>
            <p>{confirmationLabel}</p>
            <p data-testid="remove-action">{JSON.stringify(action)}</p>
            <button type="button" onClick={onClose}>
                Cancel
            </button>
        </div>
    ),
}));

function baseServers(overrides = {}) {
    return {
        primary: { serverId: 1, serverName: 'primary-server', network: 'net-1', networkId: 10, status: 'running:healthy' },
        additionalNetworks: [],
        availableNetworks: [],
        canManageAdditionalServers: true,
        hasPersistentStorage: false,
        ...overrides,
    };
}

function baseUrls(overrides = {}) {
    return {
        redeploy: '/app/1/servers/redeploy',
        stop: '/app/1/servers/stop',
        promote: '/app/1/servers/promote',
        add: '/app/1/servers/add',
        remove: '/app/1/servers/remove',
        ...overrides,
    };
}

beforeEach(() => {
    postSpy.mockClear();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('ApplicationServersTab', () => {
    it('hides primary Deploy/Stop when there are no additional servers', () => {
        render(<ApplicationServersTab servers={baseServers()} serversUrls={baseUrls()} canUpdate={true} />);
        expect(screen.queryByRole('button', { name: 'Deploy' })).not.toBeInTheDocument();
    });

    it('shows primary Deploy, and Stop only while running, once there is an additional server', () => {
        const { rerender } = render(
            <ApplicationServersTab
                servers={baseServers({
                    primary: { serverId: 1, serverName: 'p', network: 'n', networkId: 10, status: 'exited' },
                    additionalNetworks: [{ id: 2, serverId: 2, serverName: 's2', network: 'n2', isRunning: false }],
                })}
                serversUrls={baseUrls()}
                canUpdate={true}
            />,
        );
        expect(screen.getAllByRole('button', { name: 'Deploy' })[0]).toBeInTheDocument();
        expect(screen.queryAllByRole('button', { name: 'Stop' })).toHaveLength(0);

        rerender(
            <ApplicationServersTab
                servers={baseServers({
                    primary: { serverId: 1, serverName: 'p', network: 'n', networkId: 10, status: 'running:healthy' },
                    additionalNetworks: [{ id: 2, serverId: 2, serverName: 's2', network: 'n2', isRunning: false }],
                })}
                serversUrls={baseUrls()}
                canUpdate={true}
            />,
        );
        expect(screen.getAllByRole('button', { name: 'Stop' })[0]).toBeInTheDocument();
    });

    it('hides all mutating buttons on both primary and additional cards when canUpdate is false', () => {
        render(
            <ApplicationServersTab
                servers={baseServers({ additionalNetworks: [{ id: 2, serverId: 2, serverName: 's2', network: 'n2', isRunning: true }] })}
                serversUrls={baseUrls()}
                canUpdate={false}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Deploy' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Promote to Primary' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Remove from server' })).not.toBeInTheDocument();
    });

    it('only shows Stop on an additional server card while that server reports running', () => {
        const exitedPrimary = { serverId: 1, serverName: 'p', network: 'n', networkId: 10, status: 'exited' };
        const { rerender } = render(
            <ApplicationServersTab
                servers={baseServers({
                    primary: exitedPrimary,
                    additionalNetworks: [{ id: 2, serverId: 2, serverName: 's2', network: 'n2', isRunning: false }],
                })}
                serversUrls={baseUrls()}
                canUpdate={true}
            />,
        );
        expect(screen.queryAllByRole('button', { name: 'Stop' })).toHaveLength(0);

        rerender(
            <ApplicationServersTab
                servers={baseServers({
                    primary: exitedPrimary,
                    additionalNetworks: [{ id: 2, serverId: 2, serverName: 's2', network: 'n2', isRunning: true }],
                })}
                serversUrls={baseUrls()}
                canUpdate={true}
            />,
        );
        expect(screen.getAllByRole('button', { name: 'Stop' })).toHaveLength(1);
    });

    it('posts redeploy/promote/stop with the correct network and server ids', () => {
        const exitedPrimary = { serverId: 1, serverName: 'p', network: 'n', networkId: 10, status: 'exited' };
        render(
            <ApplicationServersTab
                servers={baseServers({
                    primary: exitedPrimary,
                    additionalNetworks: [{ id: 2, serverId: 22, serverName: 's2', network: 'n2', isRunning: true }],
                })}
                serversUrls={baseUrls()}
                canUpdate={true}
            />,
        );

        act(() => screen.getAllByRole('button', { name: 'Deploy' })[1].click());
        expect(postSpy).toHaveBeenCalledWith(
            '/app/1/servers/redeploy',
            { networkId: 2, serverId: 22 },
            expect.objectContaining({ preserveScroll: true }),
        );

        act(() => screen.getByRole('button', { name: 'Promote to Primary' }).click());
        expect(postSpy).toHaveBeenCalledWith(
            '/app/1/servers/promote',
            { networkId: 2, serverId: 22 },
            expect.objectContaining({ preserveScroll: true }),
        );

        act(() => screen.getByRole('button', { name: 'Stop' }).click());
        expect(postSpy).toHaveBeenCalledWith('/app/1/servers/stop', { serverId: 22 }, expect.objectContaining({ preserveScroll: true }));
    });

    it('shows a persistent-storage warning instead of the add-server picker when storage volumes exist', () => {
        render(<ApplicationServersTab servers={baseServers({ hasPersistentStorage: true })} serversUrls={baseUrls()} canUpdate={true} />);
        expect(screen.getByText(/Cannot add additional servers/)).toBeInTheDocument();
        expect(screen.queryByText('No additional servers available to attach.')).not.toBeInTheDocument();
    });

    it('shows the empty state when there is no persistent storage but no available networks either', () => {
        render(
            <ApplicationServersTab
                servers={baseServers({ hasPersistentStorage: false, availableNetworks: [] })}
                serversUrls={baseUrls()}
                canUpdate={true}
            />,
        );
        expect(screen.getByText('No additional servers available to attach.')).toBeInTheDocument();
    });

    it('adds a server by clicking its tile, only when canUpdate is true', () => {
        const { rerender } = render(
            <ApplicationServersTab
                servers={baseServers({ availableNetworks: [{ id: 5, serverId: 55, serverName: 'new-server', name: 'net-5' }] })}
                serversUrls={baseUrls()}
                canUpdate={false}
            />,
        );
        act(() => screen.getByText('Server: new-server').closest('div[class*="cursor-pointer"]').click());
        expect(postSpy).not.toHaveBeenCalled();

        rerender(
            <ApplicationServersTab
                servers={baseServers({ availableNetworks: [{ id: 5, serverId: 55, serverName: 'new-server', name: 'net-5' }] })}
                serversUrls={baseUrls()}
                canUpdate={true}
            />,
        );
        act(() => screen.getByText('Server: new-server').closest('div[class*="cursor-pointer"]').click());
        expect(postSpy).toHaveBeenCalledWith('/app/1/servers/add', { networkId: 5, serverId: 55 }, expect.objectContaining({ preserveScroll: true }));
    });

    it('opens the remove confirmation with the correct network/server payload and server-name confirmation text', () => {
        render(
            <ApplicationServersTab
                servers={baseServers({ additionalNetworks: [{ id: 7, serverId: 77, serverName: 'staging-box', network: 'n7', isRunning: false }] })}
                serversUrls={baseUrls()}
                canUpdate={true}
            />,
        );

        act(() => screen.getByRole('button', { name: 'Remove from server' }).click());

        expect(screen.getByRole('dialog', { name: 'Confirm removing application from server?' })).toBeInTheDocument();
        expect(screen.getByText(/entering the Server Name/)).toBeInTheDocument();
        expect(screen.getByTestId('remove-action')).toHaveTextContent('"networkId":7');
        expect(screen.getByTestId('remove-action')).toHaveTextContent('"serverId":77');
    });
});
