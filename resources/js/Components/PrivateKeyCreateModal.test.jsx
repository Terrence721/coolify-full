import { render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PrivateKeyCreateModal from './PrivateKeyCreateModal';

// Shared across Dashboard, Boarding/Index, Server/PrivateKey/Show, Security/PrivateKey/Index, and
// GlobalSearchModal (see the component's own doc comment) - previously entirely untested, and the
// component mocked out in Dashboard.test.jsx rather than exercised for real. Directly relevant to
// the canCreateKey bug fixed this session (Dashboard.jsx's empty state used to render this modal's
// trigger button with no permission check at all).

// React 19 patches the native <input>/<textarea> value setter to track controlled-component state -
// directly assigning `.value` then dispatching a bare event doesn't notify it. Using the real native
// setter first (bypassing React's patched one) is the standard workaround absent
// @testing-library/user-event, which isn't installed in this project.
function typeInto(element, value) {
    const proto = element.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype;
    const setter = Object.getOwnPropertyDescriptor(proto, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

function jsonResponse(data, ok = true) {
    return Promise.resolve({ ok, json: () => Promise.resolve(data) });
}

const postSpy = vi.fn();
const resetSpy = vi.fn();
const clearErrorsSpy = vi.fn();
let mockErrors = {};
let mockProcessing = false;

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
            post: (url, options) => postSpy(url, options),
            processing: mockProcessing,
            errors: mockErrors,
            reset: resetSpy,
            clearErrors: clearErrorsSpy,
        };
    },
}));

function baseProps(overrides = {}) {
    return {
        open: true,
        onClose: vi.fn(),
        createKeyUrl: '/security/private-key',
        generateKeyUrl: '/security/private-key/generate',
        onCreated: vi.fn(),
        ...overrides,
    };
}

beforeEach(() => {
    postSpy.mockClear();
    resetSpy.mockClear();
    clearErrorsSpy.mockClear();
    mockErrors = {};
    mockProcessing = false;
    document.head.innerHTML = '<meta name="csrf-token" content="test-csrf-token">';
    global.fetch = vi.fn();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('PrivateKeyCreateModal', () => {
    it('renders nothing when closed', () => {
        const { container } = render(<PrivateKeyCreateModal {...baseProps({ open: false })} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('renders empty Name/Description/Private Key fields, with no Public Key field yet', () => {
        render(<PrivateKeyCreateModal {...baseProps()} />);
        expect(screen.getByLabelText('Name')).toHaveValue('');
        expect(screen.getByLabelText('Description')).toHaveValue('');
        expect(screen.getByLabelText('Private Key')).toHaveValue('');
        expect(screen.queryByLabelText('Public Key')).not.toBeInTheDocument();
        expect(screen.queryByText(/ACTION REQUIRED/)).not.toBeInTheDocument();
    });

    it('calls onClose when the X button or the backdrop is clicked', () => {
        const onCloseX = vi.fn();
        const { unmount } = render(<PrivateKeyCreateModal {...baseProps({ onClose: onCloseX })} />);
        act(() => screen.getByRole('button', { name: '✕' }).click());
        expect(onCloseX).toHaveBeenCalledTimes(1);
        unmount();

        const onCloseBackdrop = vi.fn();
        const { container } = render(<PrivateKeyCreateModal {...baseProps({ onClose: onCloseBackdrop })} />);
        act(() => container.querySelector('.backdrop-blur-xs').click());
        expect(onCloseBackdrop).toHaveBeenCalledTimes(1);
    });

    it('updates form data as the Name/Description/Private Key fields are typed into', () => {
        render(<PrivateKeyCreateModal {...baseProps()} />);
        act(() => typeInto(screen.getByLabelText('Name'), 'my-deploy-key'));
        act(() => typeInto(screen.getByLabelText('Description'), 'CI deploy key'));
        act(() => typeInto(screen.getByLabelText('Private Key'), '-----BEGIN OPENSSH PRIVATE KEY-----'));

        expect(screen.getByLabelText('Name')).toHaveValue('my-deploy-key');
        expect(screen.getByLabelText('Description')).toHaveValue('CI deploy key');
        expect(screen.getByLabelText('Private Key')).toHaveValue('-----BEGIN OPENSSH PRIVATE KEY-----');
    });

    it('renders field-level errors for name, description, and value', () => {
        mockErrors = { name: 'The name has already been taken.', description: 'The description is invalid.', value: 'The value field is required.' };
        render(<PrivateKeyCreateModal {...baseProps()} />);

        expect(screen.getByText('The name has already been taken.')).toBeInTheDocument();
        expect(screen.getByText('The description is invalid.')).toBeInTheDocument();
        expect(screen.getByText('The value field is required.')).toBeInTheDocument();
    });

    it('disables the Continue button while processing', () => {
        mockProcessing = true;
        render(<PrivateKeyCreateModal {...baseProps()} />);
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
    });

    it('submits via post to createKeyUrl with preserveScroll', () => {
        render(<PrivateKeyCreateModal {...baseProps()} />);
        act(() => typeInto(screen.getByLabelText('Name'), 'my-deploy-key'));
        act(() => typeInto(screen.getByLabelText('Private Key'), '-----BEGIN OPENSSH PRIVATE KEY-----'));
        act(() => screen.getByRole('button', { name: 'Continue' }).click());

        expect(postSpy).toHaveBeenCalledWith(
            '/security/private-key',
            expect.objectContaining({ preserveScroll: true, onSuccess: expect.any(Function) }),
        );
    });

    it('resets the form, clears errors, and notifies onCreated once the submit succeeds', () => {
        const onCreated = vi.fn();
        render(<PrivateKeyCreateModal {...baseProps({ onCreated })} />);
        act(() => typeInto(screen.getByLabelText('Name'), 'my-deploy-key'));
        act(() => typeInto(screen.getByLabelText('Private Key'), '-----BEGIN OPENSSH PRIVATE KEY-----'));
        act(() => screen.getByRole('button', { name: 'Continue' }).click());

        const { onSuccess } = postSpy.mock.calls[0][1];
        act(() => onSuccess());

        expect(resetSpy).toHaveBeenCalledTimes(1);
        expect(clearErrorsSpy).toHaveBeenCalledTimes(1);
        expect(onCreated).toHaveBeenCalledTimes(1);
    });

    it('generates an ED25519 key: disables both generate buttons while pending, then fills the form and shows the Public Key field', async () => {
        let resolveFetch;
        global.fetch = vi.fn(() => new Promise((resolve) => (resolveFetch = resolve)));
        render(<PrivateKeyCreateModal {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'Generate new ED25519 SSH Key' }).click());

        expect(screen.getByRole('button', { name: 'Generate new ED25519 SSH Key' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Generate new RSA SSH Key' })).toBeDisabled();
        expect(global.fetch).toHaveBeenCalledWith(
            '/security/private-key/generate',
            expect.objectContaining({
                method: 'POST',
                headers: expect.objectContaining({
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': 'test-csrf-token',
                }),
                body: JSON.stringify({ type: 'ed25519' }),
            }),
        );

        await act(async () => {
            resolveFetch(
                jsonResponse({
                    name: 'generated-ed25519-key',
                    description: 'ED25519 key generated on 2026-07-24',
                    value: '-----BEGIN OPENSSH PRIVATE KEY-----\ngenerated\n-----END OPENSSH PRIVATE KEY-----',
                    publicKey: 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI generated',
                }),
            );
        });

        expect(screen.getByRole('button', { name: 'Generate new ED25519 SSH Key' })).not.toBeDisabled();
        expect(screen.getByRole('button', { name: 'Generate new RSA SSH Key' })).not.toBeDisabled();
        expect(screen.getByLabelText('Name')).toHaveValue('generated-ed25519-key');
        expect(screen.getByLabelText('Description')).toHaveValue('ED25519 key generated on 2026-07-24');
        expect(screen.getByLabelText('Private Key')).toHaveValue('-----BEGIN OPENSSH PRIVATE KEY-----\ngenerated\n-----END OPENSSH PRIVATE KEY-----');
        expect(screen.getByLabelText('Public Key')).toHaveValue('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI generated');
        expect(screen.getByText(/ACTION REQUIRED/)).toBeInTheDocument();
    });

    it('requests an RSA key when the RSA button is clicked', async () => {
        global.fetch.mockReturnValue(jsonResponse({ name: 'generated-rsa-key', description: 'RSA', value: 'rsa-value', publicKey: 'ssh-rsa AAAA' }));
        render(<PrivateKeyCreateModal {...baseProps()} />);

        await act(async () => screen.getByRole('button', { name: 'Generate new RSA SSH Key' }).click());

        expect(global.fetch).toHaveBeenCalledWith(
            '/security/private-key/generate',
            expect.objectContaining({ body: JSON.stringify({ type: 'rsa' }) }),
        );
        expect(screen.getByLabelText('Name')).toHaveValue('generated-rsa-key');
    });

    it('clears the generated Public Key field once a successful submit resets the form', async () => {
        global.fetch.mockReturnValue(jsonResponse({ name: 'generated-key', description: 'desc', value: 'value', publicKey: 'ssh-ed25519 AAAA' }));
        render(<PrivateKeyCreateModal {...baseProps()} />);

        await act(async () => screen.getByRole('button', { name: 'Generate new ED25519 SSH Key' }).click());
        expect(screen.getByLabelText('Public Key')).toBeInTheDocument();

        act(() => screen.getByRole('button', { name: 'Continue' }).click());
        const { onSuccess } = postSpy.mock.calls[0][1];
        act(() => onSuccess());

        expect(screen.queryByLabelText('Public Key')).not.toBeInTheDocument();
        expect(screen.queryByText(/ACTION REQUIRED/)).not.toBeInTheDocument();
    });
});
