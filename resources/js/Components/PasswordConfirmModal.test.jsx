import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PasswordConfirmModal from './PasswordConfirmModal';

// This one generic modal gates 13+ real destructive/sensitive actions across the app (server
// removal, storage deletion, backup deletion, etc.), each combining a different subset of its
// three independent guards: an optional typed-confirmation match, an optional password, and
// optional checkboxes with their own defaults. This suite covers those guards in combination
// (a bug in any one of them silently weakens a real confirmation flow elsewhere), the
// delete-vs-other-HTTP-method branch in submit(), and the checkbox-values-merged-into-payload
// behavior.

const deleteSpy = vi.fn((url, options) => options?.onSuccess?.());
const patchSpy = vi.fn((url, data, options) => options?.onSuccess?.());

vi.mock('@inertiajs/react', () => ({
    router: {
        delete: (url, options) => deleteSpy(url, options),
        patch: (url, data, options) => patchSpy(url, data, options),
    },
}));

function typeInto(element, value) {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

function baseProps(overrides = {}) {
    return {
        title: 'Confirm Deletion?',
        actions: ['This will permanently delete the resource.'],
        action: { url: '/resource/1', method: 'delete' },
        onClose: vi.fn(),
        onDone: vi.fn(),
        ...overrides,
    };
}

beforeEach(() => {
    deleteSpy.mockClear();
    patchSpy.mockClear();
});

describe('PasswordConfirmModal', () => {
    it('disables Confirm until a password is entered, by default', () => {
        render(<PasswordConfirmModal {...baseProps()} />);

        const confirmButton = screen.getByRole('button', { name: 'Confirm' });
        expect(confirmButton).toBeDisabled();

        act(() => typeInto(screen.getByLabelText('Password'), 'hunter2'));
        expect(confirmButton).not.toBeDisabled();
    });

    it('does not require a password when withPassword is false', () => {
        render(<PasswordConfirmModal {...baseProps({ withPassword: false })} />);

        expect(screen.getByRole('button', { name: 'Confirm' })).not.toBeDisabled();
        expect(screen.queryByLabelText('Password')).not.toBeInTheDocument();
    });

    it('keeps Confirm disabled until typed confirmation text exactly matches', () => {
        render(
            <PasswordConfirmModal
                {...baseProps({ withPassword: false, confirmationText: 'my-server', confirmationLabel: 'Type the server name' })}
            />,
        );

        const confirmButton = screen.getByRole('button', { name: 'Confirm' });
        const input = screen.getByLabelText('Type the server name');

        act(() => typeInto(input, 'my-serve'));
        expect(confirmButton).toBeDisabled();

        act(() => typeInto(input, 'my-server'));
        expect(confirmButton).not.toBeDisabled();
    });

    it('shows an inline hint once the typed confirmation text does not match, and hides it once it does', () => {
        // Found via real smoke-test usage: the Confirm button silently stayed disabled with
        // zero feedback while the confirmation field still only showed its placeholder text,
        // easily mistaken for "the button is broken" rather than "nothing has been typed yet."
        render(
            <PasswordConfirmModal
                {...baseProps({ withPassword: false, confirmationText: 'my-server', confirmationLabel: 'Type the server name' })}
            />,
        );

        const input = screen.getByLabelText('Type the server name');
        expect(screen.queryByText(/doesn't match yet/i)).not.toBeInTheDocument();

        act(() => typeInto(input, 'my-serve'));
        expect(screen.getByText(/doesn't match yet/i)).toBeInTheDocument();

        act(() => typeInto(input, 'my-server'));
        expect(screen.queryByText(/doesn't match yet/i)).not.toBeInTheDocument();
    });

    it('requires both password AND matching confirmation text when both are configured', () => {
        render(<PasswordConfirmModal {...baseProps({ confirmationText: 'my-server', confirmationLabel: 'Type the server name' })} />);

        const confirmButton = screen.getByRole('button', { name: 'Confirm' });

        act(() => typeInto(screen.getByLabelText('Password'), 'hunter2'));
        expect(confirmButton).toBeDisabled();

        act(() => typeInto(screen.getByLabelText('Type the server name'), 'my-server'));
        expect(confirmButton).not.toBeDisabled();
    });

    it('submit() is a no-op guard even if the form is force-submitted with mismatched confirmation text', () => {
        render(<PasswordConfirmModal {...baseProps({ withPassword: false, confirmationText: 'my-server' })} />);

        // Simulate a form submit event bypassing the disabled button (e.g. pressing Enter)
        const form = screen.getByRole('button', { name: 'Cancel' }).closest('form');
        act(() => form.requestSubmit());

        expect(deleteSpy).not.toHaveBeenCalled();
    });

    it('pre-selects checkboxes flagged as default, and toggling updates selection', () => {
        render(
            <PasswordConfirmModal
                {...baseProps({
                    withPassword: false,
                    checkboxes: [
                        { id: 'permanently_delete', label: 'Delete permanently', default: true },
                        { id: 'notify_team', label: 'Notify the team', default: false },
                    ],
                })}
            />,
        );

        expect(screen.getByLabelText('Delete permanently')).toBeChecked();
        expect(screen.getByLabelText('Notify the team')).not.toBeChecked();

        act(() => screen.getByLabelText('Notify the team').click());
        expect(screen.getByLabelText('Notify the team')).toBeChecked();
    });

    it('merges selected checkbox ids into the submitted data as true', () => {
        render(
            <PasswordConfirmModal
                {...baseProps({
                    withPassword: false,
                    action: { url: '/resource/1', method: 'delete', data: { networkId: 5 } },
                    checkboxes: [{ id: 'permanently_delete', label: 'Delete permanently', default: true }],
                })}
            />,
        );

        act(() => screen.getByRole('button', { name: 'Confirm' }).click());

        expect(deleteSpy).toHaveBeenCalledWith(
            '/resource/1',
            expect.objectContaining({ data: expect.objectContaining({ networkId: 5, permanently_delete: true }) }),
        );
    });

    it('routes a delete action through router.delete with data in the options object', () => {
        render(<PasswordConfirmModal {...baseProps({ withPassword: false })} />);

        act(() => screen.getByRole('button', { name: 'Confirm' }).click());

        expect(deleteSpy).toHaveBeenCalledWith('/resource/1', expect.objectContaining({ preserveScroll: true }));
        expect(patchSpy).not.toHaveBeenCalled();
    });

    it('routes a non-delete action through the matching router method with data as the second argument', () => {
        render(<PasswordConfirmModal {...baseProps({ withPassword: false, action: { url: '/server/1', method: 'patch', data: { foo: 'bar' } } })} />);

        act(() => screen.getByRole('button', { name: 'Confirm' }).click());

        expect(patchSpy).toHaveBeenCalledWith(
            '/server/1',
            expect.objectContaining({ foo: 'bar' }),
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(deleteSpy).not.toHaveBeenCalled();
    });

    it('shows the server-provided password error on failure', () => {
        patchSpy.mockImplementationOnce((url, data, options) => options.onError({ password: 'Invalid password.' }));
        render(<PasswordConfirmModal {...baseProps({ action: { url: '/server/1', method: 'patch' } })} />);

        act(() => typeInto(screen.getByLabelText('Password'), 'wrong'));
        act(() => screen.getByRole('button', { name: 'Confirm' }).click());

        expect(screen.getByText('Invalid password.')).toBeInTheDocument();
    });

    it('falls back to a generic error message when the server error has no password key', () => {
        patchSpy.mockImplementationOnce((url, data, options) => options.onError({}));
        render(<PasswordConfirmModal {...baseProps({ action: { url: '/server/1', method: 'patch' } })} />);

        act(() => typeInto(screen.getByLabelText('Password'), 'hunter2'));
        act(() => screen.getByRole('button', { name: 'Confirm' }).click());

        expect(screen.getByText('Something went wrong.')).toBeInTheDocument();
    });

    it('calls onClose when Cancel or the backdrop is clicked', () => {
        const onClose = vi.fn();
        const { container } = render(<PasswordConfirmModal {...baseProps({ onClose })} />);

        act(() => screen.getByRole('button', { name: 'Cancel' }).click());
        expect(onClose).toHaveBeenCalledTimes(1);

        act(() => container.querySelector('.absolute.inset-0').click());
        expect(onClose).toHaveBeenCalledTimes(2);
    });
});
