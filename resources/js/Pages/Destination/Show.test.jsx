import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Show from './Show';

// Real logic: canUpdate/canDelete permission gates, a hardcoded safety rule that blocks deleting
// the built-in "coolify" network even when canDelete is true, a window.prompt typed-confirmation
// delete (must match destination.name exactly), and a 3-way UI branch driven by
// destination.isStandaloneDocker (subtitle text, the Resources nav tab, and the Docker Network
// field all only appear for a standalone-Docker destination, not a swarm one).

const putSpy = vi.fn();
const deleteSpy = vi.fn();
let formErrors = {};

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            put: (url, options) => putSpy(url, options),
            processing: false,
            errors: formErrors,
        };
    },
    router: {
        delete: (url) => deleteSpy(url),
    },
}));

function baseDestination(overrides = {}) {
    return {
        uuid: 'dest-1',
        name: 'production-net',
        serverIp: '10.0.0.5',
        network: 'production-net',
        isStandaloneDocker: true,
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        destination: baseDestination(),
        canUpdate: true,
        canDelete: true,
        resourcesUrl: '/destination/dest-1/resources',
        updateUrl: '/destination/dest-1',
        deleteUrl: '/destination/dest-1',
        ...overrides,
    };
}

describe('Destination/Show', () => {
    beforeEach(() => {
        putSpy.mockClear();
        deleteSpy.mockClear();
        formErrors = {};
        vi.spyOn(window, 'prompt');
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('hides the Save button when canUpdate is false', () => {
        render(<Show {...baseProps({ canUpdate: false })} />);
        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
    });

    it('disables the name input when canUpdate is false', () => {
        render(<Show {...baseProps({ canUpdate: false })} />);
        expect(screen.getByLabelText('Name')).toBeDisabled();
    });

    it('hides the Delete button when canDelete is false', () => {
        render(<Show {...baseProps({ canDelete: false })} />);
        expect(screen.queryByRole('button', { name: 'Delete Destination' })).not.toBeInTheDocument();
    });

    it('hides the Delete button for the built-in "coolify" network even when canDelete is true', () => {
        render(<Show {...baseProps({ canDelete: true, destination: baseDestination({ network: 'coolify' }) })} />);
        expect(screen.queryByRole('button', { name: 'Delete Destination' })).not.toBeInTheDocument();
    });

    it('does not delete when the typed confirmation does not match the destination name', () => {
        window.prompt.mockReturnValueOnce('wrong-name');
        render(<Show {...baseProps()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Delete Destination' }));
        expect(deleteSpy).not.toHaveBeenCalled();
    });

    it('deletes when the typed confirmation exactly matches the destination name', () => {
        window.prompt.mockReturnValueOnce('production-net');
        render(<Show {...baseProps()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Delete Destination' }));
        expect(deleteSpy).toHaveBeenCalledWith('/destination/dest-1');
    });

    it('shows the standalone-Docker subtitle, Resources tab, and Docker Network field for a standalone destination', () => {
        render(<Show {...baseProps({ destination: baseDestination({ isStandaloneDocker: true }) })} />);
        expect(screen.getByText('A simple Docker network.')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Resources' })).toBeInTheDocument();
        expect(screen.getByLabelText('Docker Network')).toBeInTheDocument();
    });

    it('hides the standalone-only Resources tab and Docker Network field for a swarm destination', () => {
        render(<Show {...baseProps({ destination: baseDestination({ isStandaloneDocker: false }) })} />);
        expect(screen.getByText('A swarm Docker network. (Deprecated)')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Resources' })).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Docker Network')).not.toBeInTheDocument();
    });

    it('submits the updated name via put on Save', () => {
        render(<Show {...baseProps()} />);
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'renamed-net' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect(putSpy).toHaveBeenCalledWith('/destination/dest-1', undefined);
    });

    it('shows the read-only Server IP field', () => {
        render(<Show {...baseProps()} />);
        expect(screen.getByLabelText('Server IP')).toHaveValue('10.0.0.5');
        expect(screen.getByLabelText('Server IP')).toHaveAttribute('readonly');
    });
});
