import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Index from './Index';

// The /security/private-key page, live-verified end-to-end during the 2026-07-23 smoke test
// (issue #25): as Admin/Owner every key is clickable, as Member keys render view-only (not
// clickable, tooltip explains why); "+ Add" creates a real key that appears in the grid
// immediately; "Delete unused SSH Keys" only removed genuinely-unused keys (the "Unused"
// badge), both real seeded keys (in use by a server and a GithubApp respectively) were left
// untouched. This suite locks that in as automated coverage; the page was previously entirely
// untested. PrivateKeyCreateModal already has its own dedicated suite, so it's mocked out here.

const postSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => postSpy(url, data, options),
    },
}));

vi.mock('../../../Components/PrivateKeyCreateModal', () => ({
    default: ({ open, onClose }) =>
        open ? (
            <div data-testid="private-key-create-modal">
                <button type="button" onClick={onClose}>
                    Close Modal
                </button>
            </div>
        ) : null,
}));

function key(overrides = {}) {
    return {
        uuid: 'key-1',
        name: 'production-key',
        description: 'Main deploy key',
        canView: true,
        isInUse: true,
        showUrl: '/security/private-key/key-1',
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        privateKeys: [],
        canCreate: true,
        createKeyUrl: '/security/private-key/create',
        generateKeyUrl: '/security/private-key/generate',
        cleanupUnusedKeysUrl: '/security/private-key/cleanup',
        ...overrides,
    };
}

describe('Security/PrivateKey/Index', () => {
    beforeEach(() => {
        postSpy.mockClear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows "No private keys found." when there are none', () => {
        render(<Index {...baseProps()} />);
        expect(screen.getByText('No private keys found.')).toBeInTheDocument();
    });

    it('renders a viewable key as a clickable link to its show page', () => {
        render(<Index {...baseProps({ privateKeys: [key()] })} />);

        const link = screen.getByRole('link', { name: /production-key/ });
        expect(link).toHaveAttribute('href', '/security/private-key/key-1');
    });

    it('renders a non-viewable key (Member role) as view-only, not a link', () => {
        render(<Index {...baseProps({ privateKeys: [key({ canView: false })] })} />);

        expect(screen.queryByRole('link', { name: /production-key/ })).not.toBeInTheDocument();
        expect(screen.getByText('View Only')).toBeInTheDocument();
        expect(screen.getByTitle("You don't have permission to view this private key")).toBeInTheDocument();
    });

    it('shows the "Unused" badge only for a key that is not in use, regardless of view permission', () => {
        render(
            <Index
                {...baseProps({
                    privateKeys: [key({ uuid: 'k1', name: 'stale-key', isInUse: false }), key({ uuid: 'k2', name: 'active-key', isInUse: true })],
                })}
            />,
        );

        const staleCard = screen.getByRole('link', { name: /stale-key/ });
        expect(staleCard).toHaveTextContent('Unused');

        const activeCard = screen.getByRole('link', { name: /active-key/ });
        expect(activeCard).not.toHaveTextContent('Unused');
    });

    it('hides "+ Add" and "Delete unused SSH Keys" when canCreate is false', () => {
        render(<Index {...baseProps({ canCreate: false })} />);

        expect(screen.queryByRole('button', { name: '+ Add' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Delete unused SSH Keys' })).not.toBeInTheDocument();
    });

    it('opens PrivateKeyCreateModal via "+ Add" and closes it again', () => {
        render(<Index {...baseProps()} />);

        expect(screen.queryByTestId('private-key-create-modal')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: '+ Add' }));
        expect(screen.getByTestId('private-key-create-modal')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Close Modal' }));
        expect(screen.queryByTestId('private-key-create-modal')).not.toBeInTheDocument();
    });

    it('confirms via the cleanup modal before posting to cleanupUnusedKeysUrl', () => {
        render(<Index {...baseProps()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Delete unused SSH Keys' }));
        expect(screen.getByText('Confirm unused SSH Key Deletion?')).toBeInTheDocument();
        expect(postSpy).not.toHaveBeenCalled();

        // There are now two "Delete unused SSH Keys" buttons: the page header trigger and the
        // modal's confirm button - the confirm one is the second in document order.
        const confirmButtons = screen.getAllByRole('button', { name: 'Delete unused SSH Keys' });
        fireEvent.click(confirmButtons[confirmButtons.length - 1]);

        expect(postSpy).toHaveBeenCalledWith('/security/private-key/cleanup', {}, { preserveScroll: true });
        expect(screen.queryByText('Confirm unused SSH Key Deletion?')).not.toBeInTheDocument();
    });

    it('cancels the cleanup modal without posting', () => {
        render(<Index {...baseProps()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Delete unused SSH Keys' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(screen.queryByText('Confirm unused SSH Key Deletion?')).not.toBeInTheDocument();
        expect(postSpy).not.toHaveBeenCalled();
    });
});
