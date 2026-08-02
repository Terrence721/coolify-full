import { act, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Index from './Index';

// The destinations list/create page. Real logic: a derived-state slugify function
// (generateName()) that auto-fills the Name field from the selected server + already-typed
// Network value on server selection - lowercased, non-alphanumeric characters collapsed to
// hyphens - the kind of easy-to-get-subtly-wrong logic this priority favors over a thin CRUD
// wrapper. Also real: the Add button's 2-way hasServers && canCreate gate, modal open resetting
// stale form state from a prior open, and a swarm-deprecated badge shown per-destination.

const postSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (keyOrUpdater, value) => {
                if (typeof keyOrUpdater === 'function') {
                    setDataState(keyOrUpdater);
                } else {
                    setDataState((prev) => ({ ...prev, [keyOrUpdater]: value }));
                }
            },
            post: (url, options) => {
                postSpy(url, options);
                options?.onSuccess?.();
            },
            processing: false,
            errors: {},
            reset: () => setDataState(initial),
            clearErrors: () => {},
        };
    },
}));

afterEach(() => {
    postSpy.mockClear();
});

function server(overrides = {}) {
    return { id: 1, name: 'prod-server', ...overrides };
}

function baseProps(overrides = {}) {
    return {
        destinations: [],
        servers: [server()],
        hasServers: true,
        canCreate: true,
        createUrl: '/destinations',
        ...overrides,
    };
}

it('shows "No destinations found." when there are none', () => {
    render(<Index {...baseProps()} />);
    expect(screen.getByText('No destinations found.')).toBeInTheDocument();
});

it('renders each destination with its server name', () => {
    render(
        <Index
            {...baseProps({
                destinations: [{ uuid: 'a', name: 'main-network', serverName: 'prod-server', isSwarm: false, showUrl: '/destinations/a' }],
            })}
        />,
    );
    expect(screen.getByText('main-network')).toBeInTheDocument();
    expect(screen.getByText('Server: prod-server')).toBeInTheDocument();
});

it('shows the Deprecated badge only for a swarm destination', () => {
    render(
        <Index
            {...baseProps({
                destinations: [
                    { uuid: 'a', name: 'standalone', serverName: 'prod-server', isSwarm: false, showUrl: '/a' },
                    { uuid: 'b', name: 'swarm-net', serverName: 'prod-server', isSwarm: true, showUrl: '/b' },
                ],
            })}
        />,
    );
    expect(screen.getByText('Deprecated')).toBeInTheDocument();
    expect(screen.getByText('standalone')).not.toHaveTextContent('Deprecated');
});

describe('Add button visibility', () => {
    it('shows Add when there are servers and the user can create', () => {
        render(<Index {...baseProps({ hasServers: true, canCreate: true })} />);
        expect(screen.getByRole('button', { name: '+ Add' })).toBeInTheDocument();
    });

    it('hides Add when there are no servers, even if canCreate is true', () => {
        render(<Index {...baseProps({ hasServers: false, canCreate: true })} />);
        expect(screen.queryByRole('button', { name: '+ Add' })).not.toBeInTheDocument();
    });

    it('hides Add when the user cannot create, even with servers available', () => {
        render(<Index {...baseProps({ hasServers: true, canCreate: false })} />);
        expect(screen.queryByRole('button', { name: '+ Add' })).not.toBeInTheDocument();
    });
});

describe('generateName (slugify on server select)', () => {
    it('combines the server name and typed network into a lowercased, hyphenated name', () => {
        render(<Index {...baseProps({ servers: [server({ id: 1, name: 'Prod Server' })] })} />);
        fireEvent.click(screen.getByRole('button', { name: '+ Add' }));

        fireEvent.change(screen.getByLabelText('Network'), { target: { value: 'My Network!' } });
        fireEvent.change(screen.getByLabelText('Select a server'), { target: { value: '1' } });

        expect(screen.getByLabelText('Name')).toHaveValue('prod-server-my-network-');
    });

    it('does nothing when the selected server id matches no known server', () => {
        render(<Index {...baseProps({ servers: [server({ id: 1 })] })} />);
        fireEvent.click(screen.getByRole('button', { name: '+ Add' }));

        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'kept-as-is' } });
        fireEvent.change(screen.getByLabelText('Select a server'), { target: { value: '999' } });

        expect(screen.getByLabelText('Name')).toHaveValue('kept-as-is');
    });
});

describe('Add Destination modal', () => {
    it('resets stale form state from a previous open', () => {
        render(<Index {...baseProps()} />);
        fireEvent.click(screen.getByRole('button', { name: '+ Add' }));
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'leftover-value' } });
        fireEvent.click(screen.getByRole('button', { name: '✕' }));

        fireEvent.click(screen.getByRole('button', { name: '+ Add' }));
        expect(screen.getByLabelText('Name')).toHaveValue('');
    });

    it('closes without submitting when the backdrop is clicked', () => {
        const { container } = render(<Index {...baseProps()} />);
        fireEvent.click(screen.getByRole('button', { name: '+ Add' }));
        expect(screen.getByText('New Destination')).toBeInTheDocument();

        act(() => container.querySelector('.backdrop-blur-xs').click());
        expect(screen.queryByText('New Destination')).not.toBeInTheDocument();
    });

    it('submits to createUrl and closes the modal on success', () => {
        render(<Index {...baseProps()} />);
        fireEvent.click(screen.getByRole('button', { name: '+ Add' }));
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'my-dest' } });
        fireEvent.change(screen.getByLabelText('Network'), { target: { value: 'my-net' } });

        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(postSpy).toHaveBeenCalledWith('/destinations', expect.objectContaining({ preserveScroll: true }));
        expect(screen.queryByText('New Destination')).not.toBeInTheDocument();
    });
});
