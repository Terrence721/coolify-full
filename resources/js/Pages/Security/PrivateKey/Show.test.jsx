import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Show from './Show';

// The /security/private-key/{uuid} page, live-verified end-to-end during the 2026-07-20 smoke
// test (issue #25): renamed and updated the description (saved, confirmed persisted), clicked
// "Edit" to reveal the private key textarea (confirmed the real key value populates it, replacing
// the masked placeholder), then deleted via the native window.prompt() confirmation (typed the
// exact key name to confirm). This suite locks that in as automated coverage; the page was
// previously entirely untested.

const putSpy = vi.fn();
const deleteSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        delete: (url) => deleteSpy(url),
    },
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            put: (url) => putSpy(url, data),
            processing: false,
            errors: {},
        };
    },
}));

function basePrivateKey(overrides = {}) {
    return {
        id: 1,
        name: 'production-key',
        description: 'Main deploy key',
        publicKey: 'ssh-ed25519 AAAA...public',
        privateKeyValue: 'real-secret-private-key-bytes',
        isGitRelated: false,
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        privateKey: basePrivateKey(),
        canUpdate: true,
        canDelete: true,
        updateUrl: '/security/private-key/key-1',
        deleteUrl: '/security/private-key/key-1',
        ...overrides,
    };
}

describe('Security/PrivateKey/Show', () => {
    beforeEach(() => {
        putSpy.mockClear();
        deleteSpy.mockClear();
        vi.spyOn(window, 'prompt').mockReturnValue(null);
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows the masked private key value by default, with an "Edit" toggle', () => {
        render(<Show {...baseProps()} />);

        expect(screen.getByDisplayValue('••••••••••••••••••••••••')).toBeInTheDocument();
        expect(screen.queryByDisplayValue(basePrivateKey().privateKeyValue)).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument();
    });

    it('reveals the real private key value via Edit, and hides it again via Hide', () => {
        render(<Show {...baseProps()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
        expect(screen.getByDisplayValue(basePrivateKey().privateKeyValue)).toBeInTheDocument();
        expect(screen.queryByDisplayValue('••••••••••••••••••••••••')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Hide' }));
        expect(screen.getByDisplayValue('••••••••••••••••••••••••')).toBeInTheDocument();
    });

    it('shows the public key as a read-only field', () => {
        render(<Show {...baseProps()} />);

        const publicKeyField = screen.getByDisplayValue('ssh-ed25519 AAAA...public');
        expect(publicKeyField).toHaveAttribute('readonly');
    });

    it('hides the "Is used by a Git App?" checkbox when the key is not git-related', () => {
        render(<Show {...baseProps({ privateKey: basePrivateKey({ isGitRelated: false }) })} />);
        expect(screen.queryByText('Is used by a Git App?')).not.toBeInTheDocument();
    });

    it('shows the "Is used by a Git App?" checkbox when the key is git-related', () => {
        // useForm's initial data is only captured once at mount, so this needs its own render()
        // rather than a rerender() with a different privateKey prop - matches real Inertia
        // useForm() behavior, not just a test-mock limitation.
        render(<Show {...baseProps({ privateKey: basePrivateKey({ isGitRelated: true }) })} />);
        expect(screen.getByText('Is used by a Git App?')).toBeInTheDocument();
    });

    it('submits name/description changes via put(updateUrl)', () => {
        render(<Show {...baseProps()} />);

        fireEvent.change(screen.getByDisplayValue('production-key'), { target: { value: 'renamed-key' } });
        fireEvent.change(screen.getByDisplayValue('Main deploy key'), { target: { value: 'updated description' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(putSpy).toHaveBeenCalledWith(
            '/security/private-key/key-1',
            expect.objectContaining({ name: 'renamed-key', description: 'updated description' }),
        );
    });

    it('does not delete when the prompt confirmation does not match the key name', () => {
        window.prompt.mockReturnValue('wrong-name');
        render(<Show {...baseProps()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));

        expect(deleteSpy).not.toHaveBeenCalled();
    });

    it('deletes via router.delete(deleteUrl) when the prompt confirmation matches the key name exactly', () => {
        window.prompt.mockReturnValue('production-key');
        render(<Show {...baseProps()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));

        expect(deleteSpy).toHaveBeenCalledWith('/security/private-key/key-1');
    });

    it('hides Save and disables every editable field when canUpdate is false', () => {
        render(<Show {...baseProps({ canUpdate: false })} />);

        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
        expect(screen.getByDisplayValue('production-key')).toBeDisabled();
        expect(screen.getByDisplayValue('Main deploy key')).toBeDisabled();
        expect(screen.getByDisplayValue('••••••••••••••••••••••••')).toBeDisabled();
    });

    it('hides Delete when canDelete is false', () => {
        render(<Show {...baseProps({ canDelete: false })} />);
        expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument();
    });

    it('hides Delete for the reserved key (id 0), even when canDelete is true', () => {
        render(<Show {...baseProps({ privateKey: basePrivateKey({ id: 0 }) })} />);
        expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument();
    });
});
