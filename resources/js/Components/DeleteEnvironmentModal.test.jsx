import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DeleteEnvironmentModal from './DeleteEnvironmentModal';

// Manually verified live during the 2026-07-24 environment resources page smoke test (issue #25):
// on a genuinely-empty environment, "Delete Environment" worked with typed-name confirmation; on
// one with real resources, it was correctly blocked with an explanation. This suite locks that in
// as automated coverage, previously entirely untested.

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
        environment: { name: 'staging', isEmpty: true },
        deleteUrl: '/project/proj/environment/staging',
        onClose: vi.fn(),
        ...overrides,
    };
}

describe('DeleteEnvironmentModal', () => {
    beforeEach(() => {
        deleteSpy.mockClear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('blocks deletion with an explanation when the environment has resources, and offers no confirmation input', () => {
        render(<DeleteEnvironmentModal {...baseProps({ environment: { name: 'production', isEmpty: false } })} />);

        expect(screen.getByText('This environment has resources defined, please delete them first.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Permanently Delete' })).not.toBeInTheDocument();
        expect(document.getElementById('delete-environment-confirm')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    });

    it('shows the typed-confirmation flow for a genuinely empty environment', () => {
        render(<DeleteEnvironmentModal {...baseProps()} />);

        expect(screen.getByText('This will delete the selected environment.')).toBeInTheDocument();
        expect(document.getElementById('delete-environment-confirm')).toHaveAttribute('placeholder', 'staging');
        expect(screen.getByRole('button', { name: 'Permanently Delete' })).toBeDisabled();
    });

    it('keeps Permanently Delete disabled until the typed name exactly matches', () => {
        render(<DeleteEnvironmentModal {...baseProps()} />);
        const confirmButton = screen.getByRole('button', { name: 'Permanently Delete' });
        const input = document.getElementById('delete-environment-confirm');

        act(() => typeInto(input, 'stagin'));
        expect(confirmButton).toBeDisabled();

        act(() => typeInto(input, 'staging-extra'));
        expect(confirmButton).toBeDisabled();

        act(() => typeInto(input, 'staging'));
        expect(confirmButton).not.toBeDisabled();
    });

    it('calls router.delete(deleteUrl) only once the confirmation matches', () => {
        render(<DeleteEnvironmentModal {...baseProps()} />);
        const confirmButton = screen.getByRole('button', { name: 'Permanently Delete' });

        act(() => confirmButton.click());
        expect(deleteSpy).not.toHaveBeenCalled();

        act(() => typeInto(document.getElementById('delete-environment-confirm'), 'staging'));
        act(() => confirmButton.click());

        expect(deleteSpy).toHaveBeenCalledWith('/project/proj/environment/staging');
    });

    it('calls onClose via Cancel and via the backdrop click, without deleting', () => {
        const onClose = vi.fn();
        render(<DeleteEnvironmentModal {...baseProps({ onClose })} />);

        act(() => screen.getByRole('button', { name: 'Cancel' }).click());
        expect(onClose).toHaveBeenCalledTimes(1);
        expect(deleteSpy).not.toHaveBeenCalled();

        const backdrop = document.querySelector('.absolute.inset-0.h-full.w-full');
        act(() => backdrop.click());
        expect(onClose).toHaveBeenCalledTimes(2);
    });
});
