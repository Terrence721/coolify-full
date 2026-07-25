import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DeleteProjectModal from './DeleteProjectModal';

// Untested since the Livewire port (see the file's own header comment). Reused by both
// Project/Show.jsx and Project/Edit.jsx. Sibling of DeleteEnvironmentModal (already covered) but
// with two behaviors that component doesn't have: an `open` gate (renders null when closed) and a
// handleClose wrapper that resets the typed confirmation before calling onClose, so a stale value
// can't leak into the next time the modal opens.

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
        delete: (url) => deleteSpy(url),
    },
}));

function baseProps(overrides = {}) {
    return {
        open: true,
        projectName: 'my-project',
        disabled: false,
        deleteUrl: '/project/my-project',
        onClose: vi.fn(),
        ...overrides,
    };
}

describe('DeleteProjectModal', () => {
    beforeEach(() => {
        deleteSpy.mockClear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('renders nothing when open is false', () => {
        const { container } = render(<DeleteProjectModal {...baseProps({ open: false })} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('blocks deletion with an explanation when disabled, and offers no confirmation input', () => {
        render(<DeleteProjectModal {...baseProps({ disabled: true })} />);

        expect(screen.getByText('This project has resources defined, please delete them first.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Permanently Delete' })).not.toBeInTheDocument();
        expect(document.getElementById('delete-project-confirm')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    });

    it('shows the typed-confirmation flow when not disabled', () => {
        render(<DeleteProjectModal {...baseProps()} />);

        expect(screen.getByText('This will delete the selected project')).toBeInTheDocument();
        expect(document.getElementById('delete-project-confirm')).toHaveAttribute('placeholder', 'my-project');
        expect(screen.getByRole('button', { name: 'Permanently Delete' })).toBeDisabled();
    });

    it('keeps Permanently Delete disabled until the typed name exactly matches', () => {
        render(<DeleteProjectModal {...baseProps()} />);
        const confirmButton = screen.getByRole('button', { name: 'Permanently Delete' });
        const input = document.getElementById('delete-project-confirm');

        act(() => typeInto(input, 'my-projec'));
        expect(confirmButton).toBeDisabled();

        act(() => typeInto(input, 'my-project-extra'));
        expect(confirmButton).toBeDisabled();

        act(() => typeInto(input, 'my-project'));
        expect(confirmButton).not.toBeDisabled();
    });

    it('calls router.delete(deleteUrl) only once the confirmation matches', () => {
        render(<DeleteProjectModal {...baseProps()} />);
        const confirmButton = screen.getByRole('button', { name: 'Permanently Delete' });

        act(() => confirmButton.click());
        expect(deleteSpy).not.toHaveBeenCalled();

        act(() => typeInto(document.getElementById('delete-project-confirm'), 'my-project'));
        act(() => confirmButton.click());

        expect(deleteSpy).toHaveBeenCalledWith('/project/my-project');
    });

    it('calls onClose via Cancel and via the backdrop click, without deleting', () => {
        const onClose = vi.fn();
        render(<DeleteProjectModal {...baseProps({ onClose })} />);

        act(() => screen.getByRole('button', { name: 'Cancel' }).click());
        expect(onClose).toHaveBeenCalledTimes(1);
        expect(deleteSpy).not.toHaveBeenCalled();

        const backdrop = document.querySelector('.absolute.inset-0.h-full.w-full');
        act(() => backdrop.click());
        expect(onClose).toHaveBeenCalledTimes(2);
    });

    it('resets the typed confirmation on close, so it does not leak into the next open', () => {
        const onClose = vi.fn();
        const { rerender } = render(<DeleteProjectModal {...baseProps({ onClose })} />);

        act(() => typeInto(document.getElementById('delete-project-confirm'), 'my-project'));
        expect(screen.getByRole('button', { name: 'Permanently Delete' })).not.toBeDisabled();

        act(() => screen.getByRole('button', { name: 'Cancel' }).click());
        expect(onClose).toHaveBeenCalledTimes(1);

        // Simulate the parent closing then reopening the modal.
        rerender(<DeleteProjectModal {...baseProps({ open: false, onClose })} />);
        rerender(<DeleteProjectModal {...baseProps({ onClose })} />);

        expect(document.getElementById('delete-project-confirm').value).toBe('');
        expect(screen.getByRole('button', { name: 'Permanently Delete' })).toBeDisabled();
    });
});
