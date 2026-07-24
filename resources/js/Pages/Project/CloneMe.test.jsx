import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CloneMe from './CloneMe';

// Regression coverage for a real bug found via a live Playwright session during the
// 2026-07-24 environment-clone smoke test (issue #25): both "Clone to new ..." buttons used
// `disabled={processing || !destinationId}`. This dev environment's (and any single-server
// setup's) only destination has id 0 - the reserved id Server::booted() assigns to the
// localhost/first server - and `!0` is `true` in JavaScript. Selecting that destination
// correctly updated destinationId and visibly highlighted the row, but the buttons stayed
// permanently disabled with zero explanation, since the falsy-zero check never distinguished
// "id 0 selected" from "nothing selected" (`''`). Fixed to `destinationId === ''`.

const postSpy = vi.fn();
let mockErrors = {};

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => postSpy(url, data, options),
    },
    usePage: () => ({ props: { errors: mockErrors } }),
}));

function baseProps(overrides = {}) {
    return {
        destinations: [{ serverId: 0, serverName: 'production-01', destinationId: 0, destinationName: 'coolify' }],
        resources: [
            { name: 'storefront-web', type: 'Application', description: 'Main storefront' },
            { name: 'orders-db', type: 'Database', description: null },
        ],
        defaultName: 'ecommerce-clone-xyz',
        cloneUrl: '/project/proj-uuid/environment/env-uuid/clone',
        ...overrides,
    };
}

describe('Project/CloneMe', () => {
    beforeEach(() => {
        postSpy.mockClear();
        mockErrors = {};
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('renders every source resource with its type, and "-" for a missing description', () => {
        render(<CloneMe {...baseProps()} />);
        expect(screen.getByText('storefront-web')).toBeInTheDocument();
        expect(screen.getByText('Application')).toBeInTheDocument();
        expect(screen.getByText('Main storefront')).toBeInTheDocument();
        expect(screen.getByText('orders-db')).toBeInTheDocument();
        expect(screen.getByText('Database')).toBeInTheDocument();
        expect(screen.getByText('-')).toBeInTheDocument();
    });

    it('keeps both clone buttons disabled until a destination is selected', () => {
        render(<CloneMe {...baseProps()} />);
        expect(screen.getByRole('button', { name: 'Clone to new Project' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Clone to new Environment' })).toBeDisabled();
    });

    it('enables both clone buttons after selecting a destination whose id is 0', () => {
        render(<CloneMe {...baseProps()} />);

        act(() => screen.getByText('production-01').click());

        expect(screen.getByRole('button', { name: 'Clone to new Project' })).not.toBeDisabled();
        expect(screen.getByRole('button', { name: 'Clone to new Environment' })).not.toBeDisabled();
    });

    it('toggles the destination back off (and buttons back to disabled) when clicked again', () => {
        render(<CloneMe {...baseProps()} />);

        act(() => screen.getByText('production-01').click());
        expect(screen.getByRole('button', { name: 'Clone to new Project' })).not.toBeDisabled();

        act(() => screen.getByText('production-01').click());
        expect(screen.getByRole('button', { name: 'Clone to new Project' })).toBeDisabled();
    });

    it('submits type=project with the selected destination id (including 0) and the typed name', () => {
        render(<CloneMe {...baseProps()} />);

        act(() => screen.getByText('production-01').click());
        act(() => screen.getByRole('button', { name: 'Clone to new Project' }).click());

        expect(postSpy).toHaveBeenCalledWith(
            '/project/proj-uuid/environment/env-uuid/clone',
            expect.objectContaining({ type: 'project', name: 'ecommerce-clone-xyz', destination_id: 0, clone_volume_data: false }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('submits type=environment when using the other button', () => {
        render(<CloneMe {...baseProps()} />);

        act(() => screen.getByText('production-01').click());
        act(() => screen.getByRole('button', { name: 'Clone to new Environment' }).click());

        expect(postSpy).toHaveBeenCalledWith(
            '/project/proj-uuid/environment/env-uuid/clone',
            expect.objectContaining({ type: 'environment' }),
            expect.anything(),
        );
    });

    it('includes clone_volume_data: true once the checkbox is checked', () => {
        render(<CloneMe {...baseProps()} />);

        act(() => screen.getByText('production-01').click());
        act(() => document.getElementById('clone-volume-data').click());
        act(() => screen.getByRole('button', { name: 'Clone to new Project' }).click());

        expect(postSpy).toHaveBeenCalledWith(expect.anything(), expect.objectContaining({ clone_volume_data: true }), expect.anything());
    });

    it('shows the name and destination_id error messages when present', () => {
        mockErrors = { name: 'A project with this name already exists.', destination_id: 'Please select a server & destination.' };
        render(<CloneMe {...baseProps()} />);

        expect(screen.getByText('A project with this name already exists.')).toBeInTheDocument();
        expect(screen.getByText('Please select a server & destination.')).toBeInTheDocument();
    });
});
