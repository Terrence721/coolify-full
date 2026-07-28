import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Show from './Show';

// The /server/{uuid}/private-key page, live-verified end-to-end during the 2026-07-28 Server
// management smoke test (issue #26): a real throwaway key was created, switched to, switched
// back from, and deleted - including discovering that the real setKey() endpoint performs a
// genuine SSH connectivity check before committing the switch, failing safe when the new
// key's public half isn't yet authorized on the target server. This suite locks in the
// previously-untested frontend logic: the currently-used vs. use-this-key button split, the
// canCreate/canUpdate gates, and the PrivateKeyCreateModal open/close wiring.

const postSpy = vi.fn();
const privateKeyModalSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => postSpy(url, data, options),
    },
}));

vi.mock('../../../Components/ServerNavbar', () => ({ default: () => <div data-testid="server-navbar" /> }));
vi.mock('../../../Components/ServerSidebar', () => ({ default: () => <div data-testid="server-sidebar" /> }));
vi.mock('../../../Components/PrivateKeyCreateModal', () => ({
    default: (props) => {
        privateKeyModalSpy(props);
        return props.open ? <div data-testid="private-key-modal">Private key modal</div> : null;
    },
}));

function key(overrides = {}) {
    return {
        id: 1,
        uuid: 'ssh',
        name: 'Testing Host Key',
        description: 'This is a test docker container',
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        serverNavbar: {},
        sidebar: {},
        currentPrivateKeyUuid: 'ssh',
        privateKeys: [key()],
        canCreate: true,
        canUpdate: true,
        setKeyUrl: '/server/srv-uuid/private-key/set',
        checkConnectionUrl: '/server/srv-uuid/private-key/check-connection',
        createKeyUrl: '/security/private-key',
        generateKeyUrl: '/security/private-key/generate',
        ...overrides,
    };
}

describe('Server/PrivateKey/Show', () => {
    beforeEach(() => {
        postSpy.mockClear();
        privateKeyModalSpy.mockClear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows the empty state when there are no private keys', () => {
        render(<Show {...baseProps({ privateKeys: [] })} />);
        expect(screen.getByText('No private keys found.')).toBeInTheDocument();
    });

    it('renders each key with its name/description and a disabled "Currently used" button for the current one', () => {
        render(<Show {...baseProps()} />);
        expect(screen.getByText('Testing Host Key')).toBeInTheDocument();
        expect(screen.getByText('This is a test docker container')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Currently used' })).toBeDisabled();
    });

    it('renders "Use this key" for a non-current key, enabled when canUpdate is true', () => {
        render(
            <Show
                {...baseProps({
                    privateKeys: [key(), key({ id: 2, uuid: 'github-key', name: 'github-app-key', description: '' })],
                })}
            />,
        );
        const useButton = screen.getByRole('button', { name: 'Use this key' });
        expect(useButton).not.toBeDisabled();
    });

    it('disables "Use this key" when canUpdate is false', () => {
        render(
            <Show
                {...baseProps({
                    canUpdate: false,
                    privateKeys: [key(), key({ id: 2, uuid: 'github-key', name: 'github-app-key', description: '' })],
                })}
            />,
        );
        expect(screen.getByRole('button', { name: 'Use this key' })).toBeDisabled();
    });

    it('calls router.post(setKeyUrl, {private_key_id}, {preserveScroll: true}) when "Use this key" is clicked', () => {
        render(
            <Show
                {...baseProps({
                    privateKeys: [key(), key({ id: 2, uuid: 'github-key', name: 'github-app-key', description: '' })],
                })}
            />,
        );
        act(() => screen.getByRole('button', { name: 'Use this key' }).click());

        expect(postSpy).toHaveBeenCalledWith('/server/srv-uuid/private-key/set', { private_key_id: 2 }, { preserveScroll: true });
    });

    it('hides + Add when canCreate is false, hides Check connection when canUpdate is false', () => {
        render(<Show {...baseProps({ canCreate: false, canUpdate: false })} />);
        expect(screen.queryByRole('button', { name: '+ Add' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Check connection' })).not.toBeInTheDocument();
    });

    it('calls router.post(checkConnectionUrl, {}, {preserveScroll: true}) when Check connection is clicked', () => {
        render(<Show {...baseProps()} />);
        act(() => screen.getByRole('button', { name: 'Check connection' }).click());

        expect(postSpy).toHaveBeenCalledWith('/server/srv-uuid/private-key/check-connection', {}, { preserveScroll: true });
    });

    it('opens PrivateKeyCreateModal via + Add, passing the real create/generate URLs', () => {
        render(<Show {...baseProps()} />);
        expect(privateKeyModalSpy).toHaveBeenCalledWith(expect.objectContaining({ open: false }));

        act(() => screen.getByRole('button', { name: '+ Add' }).click());

        expect(screen.getByTestId('private-key-modal')).toBeInTheDocument();
        expect(privateKeyModalSpy).toHaveBeenLastCalledWith(
            expect.objectContaining({
                open: true,
                createKeyUrl: '/security/private-key',
                generateKeyUrl: '/security/private-key/generate',
            }),
        );
    });

    it('closes the modal via onCreated (matching the real create-success flow)', () => {
        render(<Show {...baseProps()} />);
        act(() => screen.getByRole('button', { name: '+ Add' }).click());
        expect(screen.getByTestId('private-key-modal')).toBeInTheDocument();

        const { onCreated } = privateKeyModalSpy.mock.calls[privateKeyModalSpy.mock.calls.length - 1][0];
        act(() => onCreated());

        expect(screen.queryByTestId('private-key-modal')).not.toBeInTheDocument();
    });

    it('closes the modal via onClose (backdrop/✕ inside the real component)', () => {
        render(<Show {...baseProps()} />);
        act(() => screen.getByRole('button', { name: '+ Add' }).click());

        const { onClose } = privateKeyModalSpy.mock.calls[privateKeyModalSpy.mock.calls.length - 1][0];
        act(() => onClose());

        expect(screen.queryByTestId('private-key-modal')).not.toBeInTheDocument();
    });
});
