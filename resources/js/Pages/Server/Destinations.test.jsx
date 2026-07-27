import { render, screen, waitFor } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Destinations from './Destinations';

// Live-verified 2026-07-26 during the Server management smoke test (issue #26): the destination
// scan and the +Add modal both confirmed working end-to-end against production-01 - a real
// StandaloneDocker record created and verified via the model directly, not just the UI's claim.
// This suite locks in the previously-untested rendering/wiring: the isFunctional gate, the
// standalone+swarm destination list and its empty state, the canCreate/canUpdate button gates,
// the scan() fetch call and its in-flight/found/empty-result branches, addNetwork()'s router.post
// call, and the New Destination modal's open/close/prefill/submit.

const postSpy = vi.fn();
const routerPostSpy = vi.fn();
const resetSpy = vi.fn();
const clearErrorsSpy = vi.fn();
let mockErrors = {};

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            post: (url, options) => postSpy(url, options),
            processing: false,
            errors: mockErrors,
            reset: resetSpy,
            clearErrors: clearErrorsSpy,
        };
    },
    router: {
        post: (url, data, options) => routerPostSpy(url, data, options),
    },
}));

vi.mock('../../Components/ServerNavbar', () => ({ default: () => <div data-testid="server-navbar" /> }));
vi.mock('../../Components/ServerSidebar', () => ({ default: () => <div data-testid="server-sidebar" /> }));

function baseProps(overrides = {}) {
    return {
        serverNavbar: {},
        sidebar: {},
        isFunctional: true,
        canUpdate: true,
        canCreate: true,
        standaloneDockers: [],
        swarmDockers: [],
        servers: [{ id: 1, name: 'production-01' }],
        scanUrl: '/server/srv-uuid/destinations/scan',
        addUrl: '/server/srv-uuid/destinations/add',
        createUrl: '/server/srv-uuid/destinations',
        ...overrides,
    };
}

function deferred() {
    let resolve;
    const promise = new Promise((res) => {
        resolve = res;
    });
    return { promise, resolve };
}

describe('Server/Destinations', () => {
    beforeEach(() => {
        postSpy.mockClear();
        resetSpy.mockClear();
        clearErrorsSpy.mockClear();
        routerPostSpy.mockClear();
        mockErrors = {};
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows "Server is not validated. Validate first." and no destination content when not functional', () => {
        render(<Destinations {...baseProps({ isFunctional: false })} />);
        expect(screen.getByText('Server is not validated. Validate first.')).toBeInTheDocument();
        expect(screen.queryByText('Destinations')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: '+ Add' })).not.toBeInTheDocument();
    });

    it('renders each standalone and swarm destination as a link to its own showUrl', () => {
        render(
            <Destinations
                {...baseProps({
                    standaloneDockers: [{ uuid: 'std-1', network: 'coolify', showUrl: '/destination/std-1' }],
                    swarmDockers: [{ uuid: 'swm-1', network: 'swarm-net', showUrl: '/destination/swm-1' }],
                })}
            />,
        );
        const stdLink = screen.getByRole('link', { name: 'coolify' });
        expect(stdLink).toHaveAttribute('href', '/destination/std-1');
        const swmLink = screen.getByRole('link', { name: 'swarm-net' });
        expect(swmLink).toHaveAttribute('href', '/destination/swm-1');
    });

    it('shows the empty state when there are no destinations at all', () => {
        render(<Destinations {...baseProps()} />);
        expect(screen.getByText('No destinations configured for this server yet.')).toBeInTheDocument();
    });

    it('hides + Add when canCreate is false', () => {
        render(<Destinations {...baseProps({ canCreate: false })} />);
        expect(screen.queryByRole('button', { name: '+ Add' })).not.toBeInTheDocument();
    });

    it('hides Scan for Destinations when canUpdate is false', () => {
        render(<Destinations {...baseProps({ canUpdate: false })} />);
        expect(screen.queryByRole('button', { name: 'Scan for Destinations' })).not.toBeInTheDocument();
    });

    it('calls fetch(scanUrl) with POST/JSON headers, showing "Scanning..." and a disabled button while in flight', async () => {
        const { promise, resolve } = deferred();
        global.fetch = vi.fn(() => promise);
        render(<Destinations {...baseProps()} />);

        const button = screen.getByRole('button', { name: 'Scan for Destinations' });
        act(() => button.click());

        expect(global.fetch).toHaveBeenCalledWith(
            '/server/srv-uuid/destinations/scan',
            expect.objectContaining({
                method: 'POST',
                headers: expect.objectContaining({ 'Content-Type': 'application/json', Accept: 'application/json' }),
            }),
        );
        expect(screen.getByRole('button', { name: 'Scanning...' })).toBeDisabled();

        await act(async () => {
            resolve({ json: () => Promise.resolve({ networks: [] }) });
            await promise;
        });

        await waitFor(() => expect(screen.getByRole('button', { name: 'Scan for Destinations' })).not.toBeDisabled());
    });

    it('renders "Found Destinations" with an Add button per network, once the scan resolves with results', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ networks: [{ name: 'new-net' }] }) }));
        render(<Destinations {...baseProps()} />);

        await act(async () => {
            screen.getByRole('button', { name: 'Scan for Destinations' }).click();
        });

        expect(screen.getByText('Found Destinations')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add new-net' })).toBeInTheDocument();
    });

    it('shows "No new destinations found on this server." when the scan resolves empty', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ networks: [] }) }));
        render(<Destinations {...baseProps()} />);

        await act(async () => {
            screen.getByRole('button', { name: 'Scan for Destinations' }).click();
        });

        expect(screen.getByText('No new destinations found on this server.')).toBeInTheDocument();
        expect(screen.queryByText('Found Destinations')).not.toBeInTheDocument();
    });

    it('calls router.post(addUrl, { name }, { preserveScroll: true }) when a found network is added', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ networks: [{ name: 'new-net' }] }) }));
        render(<Destinations {...baseProps()} />);

        await act(async () => {
            screen.getByRole('button', { name: 'Scan for Destinations' }).click();
        });
        act(() => screen.getByRole('button', { name: 'Add new-net' }).click());

        expect(routerPostSpy).toHaveBeenCalledWith('/server/srv-uuid/destinations/add', { name: 'new-net' }, { preserveScroll: true });
    });

    it('opens the New Destination modal via + Add, resetting the form and prefilling server_id to the first server', () => {
        render(<Destinations {...baseProps()} />);
        act(() => screen.getByRole('button', { name: '+ Add' }).click());

        expect(screen.getByText('New Destination')).toBeInTheDocument();
        expect(resetSpy).toHaveBeenCalled();
        expect(clearErrorsSpy).toHaveBeenCalled();
        expect(screen.getByLabelText('Select a server')).toHaveValue('1');
    });

    it('closes the modal via the ✕ button and via the backdrop click', () => {
        render(<Destinations {...baseProps()} />);
        act(() => screen.getByRole('button', { name: '+ Add' }).click());

        act(() => screen.getByRole('button', { name: '✕' }).click());
        expect(screen.queryByText('New Destination')).not.toBeInTheDocument();

        act(() => screen.getByRole('button', { name: '+ Add' }).click());
        const backdrop = document.querySelector('.absolute.inset-0.h-full.w-full');
        act(() => backdrop.click());
        expect(screen.queryByText('New Destination')).not.toBeInTheDocument();
    });

    it('submits the create form to createUrl and closes the modal onSuccess', () => {
        render(<Destinations {...baseProps()} />);
        act(() => screen.getByRole('button', { name: '+ Add' }).click());

        act(() => {
            const nameSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
            const nameInput = document.getElementById('server-destination-name');
            nameSetter.call(nameInput, 'my-network');
            nameInput.dispatchEvent(new Event('input', { bubbles: true }));

            const networkInput = document.getElementById('server-destination-network');
            nameSetter.call(networkInput, 'my-network');
            networkInput.dispatchEvent(new Event('input', { bubbles: true }));
        });

        act(() => screen.getByRole('button', { name: 'Continue' }).click());

        expect(postSpy).toHaveBeenCalledWith('/server/srv-uuid/destinations', expect.objectContaining({ onSuccess: expect.any(Function) }));

        act(() => postSpy.mock.calls[0][1].onSuccess());
        expect(screen.queryByText('New Destination')).not.toBeInTheDocument();
    });

    it('renders per-field errors for name, network, and server_id', () => {
        mockErrors = { name: 'The name has already been taken.', network: 'The network field is required.', server_id: 'Invalid server.' };
        render(<Destinations {...baseProps()} />);
        act(() => screen.getByRole('button', { name: '+ Add' }).click());

        expect(screen.getByText('The name has already been taken.')).toBeInTheDocument();
        expect(screen.getByText('The network field is required.')).toBeInTheDocument();
        expect(screen.getByText('Invalid server.')).toBeInTheDocument();
    });
});
