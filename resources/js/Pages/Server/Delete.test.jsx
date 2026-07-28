import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Delete from './Delete';

// The /server/{uuid}/danger page, live-verified end-to-end during the 2026-07-25 Server
// management smoke test (issue #26): a real throwaway server was deleted through this exact
// flow - typed-confirmation gate, password field, redirect to /servers on success, confirmed
// independently via Server::count(). This suite locks in the previously-untested frontend
// logic: the localhost (id 0) guard, the hasResources warning, the modal open/close wiring,
// the dynamic force-delete-resources checkboxes, and the typed-name + password submit gate.

// React 19 patches the native <input> value setter to track controlled-component state - directly
// assigning `.value` then dispatching a bare event doesn't notify it. Using the real native setter
// first (bypassing React's patched one) is the standard workaround absent
// @testing-library/user-event, which isn't installed in this project.
function typeInto(element, value) {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

const deleteSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        delete: (url, options) => deleteSpy(url, options),
    },
}));

vi.mock('../../Components/ServerNavbar', () => ({ default: () => <div data-testid="server-navbar" /> }));
vi.mock('../../Components/ServerSidebar', () => ({ default: () => <div data-testid="server-sidebar" /> }));

function baseProps(overrides = {}) {
    return {
        serverNavbar: {},
        sidebar: {},
        server: { id: 1, name: 'production-01' },
        hasResources: false,
        checkboxes: [],
        destroyUrl: '/server/srv-uuid',
        ...overrides,
    };
}

describe('Server/Delete', () => {
    beforeEach(() => {
        deleteSpy.mockClear();
    });

    it('renders only the navbar/sidebar shell for the localhost server (id 0)', () => {
        render(<Delete {...baseProps({ server: { id: 0, name: 'localhost' } })} />);
        expect(screen.getByTestId('server-navbar')).toBeInTheDocument();
        expect(screen.getByTestId('server-sidebar')).toBeInTheDocument();
        expect(screen.queryByText('Danger Zone')).not.toBeInTheDocument();
    });

    it('shows the Danger Zone with a Delete button for a normal server', () => {
        render(<Delete {...baseProps()} />);
        expect(screen.getByText('Danger Zone')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Delete' })).toBeInTheDocument();
    });

    it('does not show the has-resources warning when the server has none', () => {
        render(<Delete {...baseProps({ hasResources: false })} />);
        expect(screen.queryByText(/This server has resources/)).not.toBeInTheDocument();
    });

    it('shows the has-resources warning when the server has resources', () => {
        render(<Delete {...baseProps({ hasResources: true })} />);
        expect(screen.getByText(/This server has resources/)).toBeInTheDocument();
    });

    it('opens the confirmation modal when Delete is clicked', () => {
        render(<Delete {...baseProps()} />);
        expect(screen.queryByText('Confirm Server Deletion?')).not.toBeInTheDocument();

        act(() => screen.getByRole('button', { name: 'Delete' }).click());
        expect(screen.getByText('Confirm Server Deletion?')).toBeInTheDocument();
    });

    it('closes the modal via the ✕ button', () => {
        render(<Delete {...baseProps()} />);
        act(() => screen.getByRole('button', { name: 'Delete' }).click());

        act(() => screen.getByRole('button', { name: '✕' }).click());
        expect(screen.queryByText('Confirm Server Deletion?')).not.toBeInTheDocument();
    });

    it('closes the modal via the backdrop click', () => {
        const { container } = render(<Delete {...baseProps()} />);
        act(() => screen.getByRole('button', { name: 'Delete' }).click());

        const backdrop = container.querySelector('.backdrop-blur-xs');
        act(() => backdrop.click());
        expect(screen.queryByText('Confirm Server Deletion?')).not.toBeInTheDocument();
    });

    it('renders no checkboxes when none are provided', () => {
        render(<Delete {...baseProps({ checkboxes: [] })} />);
        act(() => screen.getByRole('button', { name: 'Delete' }).click());
        expect(screen.queryAllByRole('checkbox')).toHaveLength(0);
    });

    it('renders the dynamic force-delete-resources checkboxes and toggles them', () => {
        render(
            <Delete
                {...baseProps({
                    hasResources: true,
                    checkboxes: [
                        { id: 'delete_configurations', label: 'Delete configuration files' },
                        { id: 'delete_volumes', label: 'Delete volumes' },
                    ],
                })}
            />,
        );
        act(() => screen.getByRole('button', { name: 'Delete' }).click());

        const configCheckbox = screen.getByLabelText('Delete configuration files');
        const volumesCheckbox = screen.getByLabelText('Delete volumes');
        expect(configCheckbox).not.toBeChecked();
        expect(volumesCheckbox).not.toBeChecked();

        act(() => configCheckbox.click());
        expect(configCheckbox).toBeChecked();
        expect(volumesCheckbox).not.toBeChecked();

        act(() => configCheckbox.click());
        expect(configCheckbox).not.toBeChecked();
    });

    it('keeps the submit button disabled until the typed name matches and a password is entered', () => {
        render(<Delete {...baseProps({ server: { id: 1, name: 'production-01' } })} />);
        act(() => screen.getByRole('button', { name: 'Delete' }).click());

        const modalSubmit = screen.getAllByRole('button', { name: 'Delete' }).at(-1);
        expect(modalSubmit).toBeDisabled();

        act(() => typeInto(screen.getByLabelText(/entering the Server Name below/), 'wrong-name'));
        expect(modalSubmit).toBeDisabled();

        act(() => typeInto(screen.getByLabelText(/entering the Server Name below/), 'production-01'));
        expect(modalSubmit).toBeDisabled();

        act(() => typeInto(screen.getByLabelText('Password'), 'secret'));
        expect(modalSubmit).toBeEnabled();
    });

    it('submits router.delete with the password and selected actions once enabled', () => {
        render(
            <Delete
                {...baseProps({
                    server: { id: 1, name: 'production-01' },
                    hasResources: true,
                    checkboxes: [{ id: 'delete_volumes', label: 'Delete volumes' }],
                    destroyUrl: '/server/srv-uuid',
                })}
            />,
        );
        act(() => screen.getByRole('button', { name: 'Delete' }).click());

        act(() => screen.getByLabelText('Delete volumes').click());
        act(() => typeInto(screen.getByLabelText(/entering the Server Name below/), 'production-01'));
        act(() => typeInto(screen.getByLabelText('Password'), 'secret'));

        const modalSubmit = screen.getAllByRole('button', { name: 'Delete' }).at(-1);
        act(() => modalSubmit.click());

        expect(deleteSpy).toHaveBeenCalledWith('/server/srv-uuid', {
            data: { password: 'secret', selected_actions: ['delete_volumes'] },
        });
    });

    it('re-checks the typed name inside submit() itself, not just the disabled button (defense in depth)', () => {
        render(<Delete {...baseProps({ server: { id: 1, name: 'production-01' } })} />);
        act(() => screen.getByRole('button', { name: 'Delete' }).click());

        act(() => typeInto(screen.getByLabelText(/entering the Server Name below/), 'not-the-name'));
        act(() => typeInto(screen.getByLabelText('Password'), 'secret'));

        const form = screen.getByLabelText('Password').closest('form');
        act(() => {
            form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        });

        expect(deleteSpy).not.toHaveBeenCalled();
    });
});
