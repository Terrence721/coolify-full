import { render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AddServerModal from './AddServerModal';

// Manually verified live end-to-end during the 2026-07-24 Servers smoke test (issue #25) against
// a real throwaway server: the IP flow's create-and-redirect, the duplicate-IP error, and the
// "Add via Hetzner Cloud ->" link all worked correctly in the real browser. This suite locks that
// in as automated coverage - previously entirely untested - plus the defaulted form state, the
// backdrop/X-button close paths, and error rendering per field.

// React 19 patches the native <input> value setter to track controlled-component state - directly
// assigning `.value` then dispatching a bare event doesn't notify it. Using the real native setter
// first (bypassing React's patched one) is the standard workaround absent
// @testing-library/user-event, which isn't installed in this project.
function typeInto(element, value) {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

const postSpy = vi.fn();
const visitSpy = vi.fn();
let mockErrors = {};
let mockProcessing = false;

vi.mock('@inertiajs/react', () => ({
    router: {
        visit: (url) => visitSpy(url),
    },
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            post: (url, options) => postSpy(url, data, options),
            processing: mockProcessing,
            errors: mockErrors,
        };
    },
}));

function baseProps(overrides = {}) {
    return {
        privateKeys: [
            { id: 1, name: 'Default Key' },
            { id: 2, name: 'Deploy Key' },
        ],
        defaultPrivateKeyId: 1,
        defaultName: 'crimson-falcon',
        storeUrl: '/servers',
        onClose: vi.fn(),
        ...overrides,
    };
}

describe('AddServerModal', () => {
    beforeEach(() => {
        postSpy.mockClear();
        visitSpy.mockClear();
        mockErrors = {};
        mockProcessing = false;
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('prefills the form with defaultName, root user, port 22, and the default private key', () => {
        render(<AddServerModal {...baseProps()} />);

        expect(document.getElementById('add-server-name').value).toBe('crimson-falcon');
        expect(document.getElementById('add-server-description').value).toBe('');
        expect(document.getElementById('add-server-ip').value).toBe('');
        expect(document.getElementById('add-server-user').value).toBe('root');
        expect(document.getElementById('add-server-port').value).toBe('22');
        expect(document.getElementById('add-server-private-key-id').value).toBe('1');
    });

    it('defaults private_key_id to an empty string when defaultPrivateKeyId is not provided', () => {
        render(<AddServerModal {...baseProps({ defaultPrivateKeyId: undefined })} />);
        expect(document.getElementById('add-server-private-key-id').value).toBe('');
    });

    it('lists every private key as an option, plus a disabled placeholder', () => {
        render(<AddServerModal {...baseProps()} />);
        const select = document.getElementById('add-server-private-key-id');
        const options = Array.from(select.options).map((o) => ({ value: o.value, text: o.textContent, disabled: o.disabled }));

        expect(options).toEqual([
            { value: '', text: 'Select a private key', disabled: true },
            { value: '1', text: 'Default Key', disabled: false },
            { value: '2', text: 'Deploy Key', disabled: false },
        ]);
    });

    it('closes via the backdrop click and the X button', () => {
        const onClose = vi.fn();
        render(<AddServerModal {...baseProps({ onClose })} />);

        act(() => screen.getByRole('button', { name: '✕' }).click());
        expect(onClose).toHaveBeenCalledTimes(1);

        const backdrop = document.querySelector('.absolute.inset-0.h-full.w-full');
        act(() => backdrop.click());
        expect(onClose).toHaveBeenCalledTimes(2);
    });

    it('submits an edited IP via useForm.post to storeUrl, closing on success', () => {
        const onClose = vi.fn();
        render(<AddServerModal {...baseProps({ onClose })} />);

        act(() => typeInto(document.getElementById('add-server-ip'), '198.51.100.50'));
        act(() => screen.getByRole('button', { name: 'Continue' }).click());

        expect(postSpy).toHaveBeenCalledWith(
            '/servers',
            expect.objectContaining({ ip: '198.51.100.50', name: 'crimson-falcon' }),
            expect.objectContaining({ preserveScroll: true }),
        );

        // Simulate Inertia calling the success callback after a real 302 redirect.
        const options = postSpy.mock.calls.at(-1)[2];
        expect(onClose).not.toHaveBeenCalled();
        act(() => options.onSuccess());
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('disables the Continue button while processing', () => {
        mockProcessing = true;
        render(<AddServerModal {...baseProps()} />);
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
    });

    it('toggles is_build_server via the checkbox', () => {
        render(<AddServerModal {...baseProps()} />);
        const checkbox = document.getElementById('add-server-is-build-server');
        expect(checkbox.checked).toBe(false);

        act(() => checkbox.click());

        expect(checkbox.checked).toBe(true);
    });

    it('renders a per-field error message when present, e.g. a duplicate-IP style backend error', () => {
        mockErrors = { ip: 'A server with this IP/Domain already exists in your team.' };
        render(<AddServerModal {...baseProps()} />);

        expect(screen.getByText('A server with this IP/Domain already exists in your team.')).toBeInTheDocument();
        expect(screen.queryByText(/name/i, { selector: '.text-error' })).not.toBeInTheDocument();
    });

    it('closes the modal and navigates to the Hetzner wizard via "Add via Hetzner Cloud"', () => {
        const onClose = vi.fn();
        render(<AddServerModal {...baseProps({ onClose })} />);

        act(() => screen.getByRole('button', { name: 'Add via Hetzner Cloud →' }).click());

        expect(onClose).toHaveBeenCalledTimes(1);
        expect(visitSpy).toHaveBeenCalledWith('/servers/new/hetzner');
    });
});
