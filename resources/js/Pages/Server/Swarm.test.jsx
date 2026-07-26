import { render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Swarm from './Swarm';

// Live-verified 2026-07-25 during the Server management smoke test (issue #26): the page rendered
// correctly against the real server (Deprecated banner, both checkboxes present), though the
// instant-save toggle itself wasn't exercised live that cycle. This suite covers it: the mutually-
// exclusive Manager/Worker checkboxes and the instant-save `put` call each toggle fires.

const putSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (keyOrObjectOrFn, value) => {
                if (typeof keyOrObjectOrFn === 'function') {
                    setDataState(keyOrObjectOrFn);
                } else if (typeof keyOrObjectOrFn === 'string') {
                    setDataState((prev) => ({ ...prev, [keyOrObjectOrFn]: value }));
                } else {
                    setDataState(keyOrObjectOrFn);
                }
            },
            put: (url, options) => putSpy(url, options),
        };
    },
}));

vi.mock('../../Components/ServerNavbar', () => ({ default: () => <div data-testid="server-navbar" /> }));
vi.mock('../../Components/ServerSidebar', () => ({ default: () => <div data-testid="server-sidebar" /> }));

function baseProps(overrides = {}) {
    return {
        serverNavbar: {},
        sidebar: {},
        deprecationNotice: 'Docker Swarm is deprecated and will be removed in Coolify v5.',
        isSwarmManager: false,
        isSwarmWorker: false,
        updateUrl: '/server/srv-uuid/swarm',
        ...overrides,
    };
}

describe('Server/Swarm', () => {
    beforeEach(() => {
        putSpy.mockClear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('renders the Deprecated badge, the real deprecation notice, and the docs link', () => {
        render(<Swarm {...baseProps()} />);
        expect(screen.getByText('Deprecated')).toBeInTheDocument();
        expect(screen.getByText('Docker Swarm is deprecated and will be removed in Coolify v5.')).toBeInTheDocument();
        const docsLink = screen.getByRole('link', { name: 'here' });
        expect(docsLink).toHaveAttribute('href', 'https://coolify.io/docs/knowledge-base/docker/swarm');
        expect(docsLink).toHaveAttribute('target', '_blank');
    });

    it('starts with neither checkbox disabled when neither role is set', () => {
        render(<Swarm {...baseProps()} />);
        expect(screen.getByRole('checkbox', { name: 'Is it a Swarm Manager?' })).not.toBeDisabled();
        expect(screen.getByRole('checkbox', { name: 'Is it a Swarm Worker?' })).not.toBeDisabled();
    });

    it('checking Manager instant-saves and disables the Worker checkbox', () => {
        render(<Swarm {...baseProps()} />);
        act(() => screen.getByRole('checkbox', { name: 'Is it a Swarm Manager?' }).click());

        expect(putSpy).toHaveBeenCalledWith('/server/srv-uuid/swarm', {
            data: { is_swarm_manager: true, is_swarm_worker: false },
            preserveScroll: true,
        });
        expect(screen.getByRole('checkbox', { name: 'Is it a Swarm Manager?' })).toBeChecked();
        expect(screen.getByRole('checkbox', { name: 'Is it a Swarm Worker?' })).toBeDisabled();
    });

    it('checking Worker instant-saves and disables the Manager checkbox', () => {
        render(<Swarm {...baseProps()} />);
        act(() => screen.getByRole('checkbox', { name: 'Is it a Swarm Worker?' }).click());

        expect(putSpy).toHaveBeenCalledWith('/server/srv-uuid/swarm', {
            data: { is_swarm_manager: false, is_swarm_worker: true },
            preserveScroll: true,
        });
        expect(screen.getByRole('checkbox', { name: 'Is it a Swarm Worker?' })).toBeChecked();
        expect(screen.getByRole('checkbox', { name: 'Is it a Swarm Manager?' })).toBeDisabled();
    });

    it('un-checking Manager re-enables the Worker checkbox', () => {
        render(<Swarm {...baseProps({ isSwarmManager: true })} />);
        expect(screen.getByRole('checkbox', { name: 'Is it a Swarm Worker?' })).toBeDisabled();

        act(() => screen.getByRole('checkbox', { name: 'Is it a Swarm Manager?' }).click());

        expect(putSpy).toHaveBeenLastCalledWith('/server/srv-uuid/swarm', {
            data: { is_swarm_manager: false, is_swarm_worker: false },
            preserveScroll: true,
        });
        expect(screen.getByRole('checkbox', { name: 'Is it a Swarm Worker?' })).not.toBeDisabled();
    });
});
