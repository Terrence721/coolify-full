import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Index from './Index';

// Manually verified live end-to-end during the 2026-07-24 Servers smoke test (issue #25): a real
// throwaway server created via the IP flow immediately showed the red-bordered "Not reachable &
// Not usable by Coolify" state (both default false on creation), and force_disabled correctly
// rendered as a third combined state ("...Disabled by the system"). This suite locks in that
// badge/border-combination logic - previously entirely untested - plus the empty state and the
// canCreate gate. AddServerModal itself is mocked out (it's its own separate, not-yet-covered
// backlog item) so this suite stays focused on Index's own conditional rendering.

const addServerModalSpy = vi.fn();

vi.mock('../../Components/AddServerModal', () => ({
    default: (props) => {
        addServerModalSpy(props);
        return (
            <div data-testid="add-server-modal">
                <button type="button" onClick={props.onClose}>
                    Close Modal
                </button>
            </div>
        );
    },
}));

function baseServer(overrides = {}) {
    return {
        uuid: 'server-uuid-1',
        name: 'production-01',
        description: 'Primary Docker host',
        isReachable: true,
        isUsable: true,
        forceDisabled: false,
        showUrl: '/server/server-uuid-1',
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        servers: [baseServer()],
        canCreate: true,
        privateKeys: [{ id: 1, name: 'Default Key' }],
        defaultPrivateKeyId: 1,
        defaultName: 'random-server-name',
        storeUrl: '/servers',
        ...overrides,
    };
}

describe('Server/Index', () => {
    it('shows the empty state when there are no servers', () => {
        render(<Index {...baseProps({ servers: [] })} />);
        expect(screen.getByText(/No servers found/)).toBeInTheDocument();
    });

    it("renders each server's name, description, and links to its Show page", () => {
        render(<Index {...baseProps()} />);
        expect(screen.getByText('production-01')).toBeInTheDocument();
        expect(screen.getByText('Primary Docker host')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /production-01/ })).toHaveAttribute('href', '/server/server-uuid-1');
    });

    it('shows no red border and no error text for a reachable, usable, non-disabled server', () => {
        render(<Index {...baseProps()} />);
        const link = screen.getByRole('link', { name: /production-01/ });
        expect(link.className).not.toContain('border-red-500');
        expect(screen.queryByText('Not reachable')).not.toBeInTheDocument();
        expect(screen.queryByText('Not usable by Coolify')).not.toBeInTheDocument();
    });

    it('borders red and shows "Not reachable" when unreachable, even if usable', () => {
        render(<Index {...baseProps({ servers: [baseServer({ isReachable: false, isUsable: true })] })} />);
        const link = screen.getByRole('link', { name: /production-01/ });
        expect(link.className).toContain('border-red-500');
        expect(screen.getByText('Not reachable')).toBeInTheDocument();
        expect(screen.queryByText('Not usable by Coolify')).not.toBeInTheDocument();
        expect(screen.queryByText('&')).not.toBeInTheDocument();
    });

    it('shows "Not usable by Coolify" with no red border when only isUsable is false', () => {
        render(<Index {...baseProps({ servers: [baseServer({ isReachable: true, isUsable: false })] })} />);
        const link = screen.getByRole('link', { name: /production-01/ });
        expect(link.className).not.toContain('border-red-500');
        expect(screen.getByText('Not usable by Coolify')).toBeInTheDocument();
        expect(screen.queryByText('Not reachable')).not.toBeInTheDocument();
    });

    it('joins both messages with "&" when neither reachable nor usable', () => {
        render(<Index {...baseProps({ servers: [baseServer({ isReachable: false, isUsable: false })] })} />);
        expect(screen.getByText('Not reachable')).toBeInTheDocument();
        expect(screen.getByText('&')).toBeInTheDocument();
        expect(screen.getByText('Not usable by Coolify')).toBeInTheDocument();
    });

    it('borders red and shows "Disabled by the system" for a force-disabled server, alongside the other states', () => {
        render(<Index {...baseProps({ servers: [baseServer({ isReachable: false, isUsable: false, forceDisabled: true })] })} />);
        const link = screen.getByRole('link', { name: /production-01/ });
        expect(link.className).toContain('border-red-500');
        expect(screen.getByText('Not reachable')).toBeInTheDocument();
        expect(screen.getByText('Not usable by Coolify')).toBeInTheDocument();
        expect(screen.getByText('Disabled by the system')).toBeInTheDocument();
    });

    it('borders red when force-disabled even if otherwise reachable and usable', () => {
        render(<Index {...baseProps({ servers: [baseServer({ isReachable: true, isUsable: true, forceDisabled: true })] })} />);
        const link = screen.getByRole('link', { name: /production-01/ });
        expect(link.className).toContain('border-red-500');
        expect(screen.getByText('Disabled by the system')).toBeInTheDocument();
        expect(screen.queryByText('Not reachable')).not.toBeInTheDocument();
    });

    it('only shows the "+ Add" button when canCreate is true', () => {
        const { unmount } = render(<Index {...baseProps({ canCreate: false })} />);
        expect(screen.queryByRole('button', { name: '+ Add' })).not.toBeInTheDocument();
        unmount();

        render(<Index {...baseProps({ canCreate: true })} />);
        expect(screen.getByRole('button', { name: '+ Add' })).toBeInTheDocument();
    });

    it('opens AddServerModal with the right props on "+ Add", and closes it via onClose', () => {
        addServerModalSpy.mockClear();
        render(<Index {...baseProps()} />);

        expect(screen.queryByTestId('add-server-modal')).not.toBeInTheDocument();

        act(() => screen.getByRole('button', { name: '+ Add' }).click());

        expect(screen.getByTestId('add-server-modal')).toBeInTheDocument();
        expect(addServerModalSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                privateKeys: [{ id: 1, name: 'Default Key' }],
                defaultPrivateKeyId: 1,
                defaultName: 'random-server-name',
                storeUrl: '/servers',
            }),
        );

        act(() => screen.getByRole('button', { name: 'Close Modal' }).click());
        expect(screen.queryByTestId('add-server-modal')).not.toBeInTheDocument();
    });
});
