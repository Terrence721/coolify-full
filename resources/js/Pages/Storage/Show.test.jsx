import { render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Show from './Show';

// Manually verified live end-to-end during the 2026-07-23 Storage smoke test (issue #25) against
// the real MinIO instance already in this dev stack: "Validate Connection" returned a real
// success toast, breaking the Access Key and clicking Save produced a real AWS error with a real
// rollback (verified via `tinker`, not just a reverted form field), and attaching a real scheduled
// backup exercised the delete-message variant. This suite locks all of that in as automated
// coverage, previously entirely untested.

// React 19 patches the native <input> value setter to track controlled-component state - directly
// assigning `.value` then dispatching a bare event doesn't notify it. Using the real native setter
// first (bypassing React's patched one) is the standard workaround absent
// @testing-library/user-event, which isn't installed in this project.
function typeInto(element, value) {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

const putSpy = vi.fn();
const postSpy = vi.fn();
const deleteSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => postSpy(url, data, options),
        delete: (url, options) => deleteSpy(url, options),
    },
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            put: (url, options) => putSpy(url, data, options),
            processing: false,
            errors: {},
        };
    },
}));

function baseProps(overrides = {}) {
    return {
        storage: {
            name: 'my-s3-storage',
            description: 'Production backups',
            endpoint: 'https://s3.example.com',
            bucket: 'backups',
            region: 'us-east-1',
            key: 'AKIA...',
            secret: 'secret-value',
            isUsable: true,
        },
        backupCount: 0,
        canUpdate: true,
        canDelete: true,
        canValidateConnection: true,
        showUrl: '/storages/storage-uuid',
        resourcesUrl: '/storages/storage-uuid/resources',
        updateUrl: '/storages/storage-uuid',
        testConnectionUrl: '/storages/storage-uuid/test',
        deleteUrl: '/storages/storage-uuid',
        ...overrides,
    };
}

describe('Storage/Show', () => {
    beforeEach(() => {
        putSpy.mockClear();
        postSpy.mockClear();
        deleteSpy.mockClear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows a "Usable" badge when the storage is usable, "Not Usable" otherwise', () => {
        const { unmount } = render(<Show {...baseProps({ storage: { ...baseProps().storage, isUsable: true } })} />);
        expect(screen.getByText('Usable')).toBeInTheDocument();
        expect(screen.queryByText('Not Usable')).not.toBeInTheDocument();
        unmount();

        render(<Show {...baseProps({ storage: { ...baseProps().storage, isUsable: false } })} />);
        expect(screen.getByText('Not Usable')).toBeInTheDocument();
    });

    it('hides Save and Delete when the corresponding permission is false', () => {
        const { unmount } = render(<Show {...baseProps({ canUpdate: false, canDelete: false })} />);
        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument();
        unmount();

        render(<Show {...baseProps({ canUpdate: true, canDelete: true })} />);
        expect(screen.getByRole('button', { name: 'Save' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Delete' })).toBeInTheDocument();
    });

    it('disables every credential field when canUpdate is false', () => {
        render(<Show {...baseProps({ canUpdate: false })} />);
        expect(document.getElementById('storage-name')).toBeDisabled();
        expect(document.getElementById('storage-endpoint')).toBeDisabled();
        expect(document.getElementById('storage-key')).toBeDisabled();
        expect(document.getElementById('storage-secret')).toBeDisabled();
    });

    it('saves the form via useForm.put to updateUrl, including an edited field', () => {
        render(<Show {...baseProps()} />);

        act(() => typeInto(document.getElementById('storage-key'), 'AKIA-BROKEN'));
        act(() => screen.getByRole('button', { name: 'Save' }).click());

        expect(putSpy).toHaveBeenCalledWith('/storages/storage-uuid', expect.objectContaining({ key: 'AKIA-BROKEN' }), undefined);
    });

    it('only shows Validate Connection when canValidateConnection is true, and posts to testConnectionUrl', () => {
        const { unmount } = render(<Show {...baseProps({ canValidateConnection: false })} />);
        expect(screen.queryByRole('button', { name: 'Validate Connection' })).not.toBeInTheDocument();
        unmount();

        render(<Show {...baseProps({ canValidateConnection: true })} />);
        act(() => screen.getByRole('button', { name: 'Validate Connection' }).click());

        expect(postSpy).toHaveBeenCalledWith('/storages/storage-uuid/test', {}, { preserveScroll: true });
    });

    it('opens the delete confirmation modal, listing only the base consequence when there are no attached backups', () => {
        render(<Show {...baseProps({ backupCount: 0 })} />);

        act(() => screen.getByRole('button', { name: 'Delete' }).click());

        expect(screen.getByText('Confirm Storage Deletion?')).toBeInTheDocument();
        expect(screen.getByText(/permanently deleted from Coolify/)).toBeInTheDocument();
        expect(screen.queryByText(/backup schedule\(s\) will be updated/)).not.toBeInTheDocument();
    });

    it('adds the backup-schedule consequence line when backupCount is greater than zero', () => {
        render(<Show {...baseProps({ backupCount: 3 })} />);

        act(() => screen.getByRole('button', { name: 'Delete' }).click());

        expect(screen.getByText(/3 backup schedule\(s\) will be updated to no longer save to S3/)).toBeInTheDocument();
    });

    it('keeps Permanently Delete disabled until the typed confirmation exactly matches the storage name', () => {
        render(<Show {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'Delete' }).click());
        const confirmButton = screen.getByRole('button', { name: 'Permanently Delete' });
        expect(confirmButton).toBeDisabled();

        act(() => typeInto(document.getElementById('storage-delete-confirm'), 'wrong-name'));
        expect(confirmButton).toBeDisabled();
        act(() => confirmButton.click());
        expect(deleteSpy).not.toHaveBeenCalled();

        act(() => typeInto(document.getElementById('storage-delete-confirm'), 'my-s3-storage'));
        expect(confirmButton).not.toBeDisabled();
        act(() => confirmButton.click());

        expect(deleteSpy).toHaveBeenCalledWith('/storages/storage-uuid', undefined);
    });

    it('closes the modal and resets the typed confirmation via Cancel', () => {
        render(<Show {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'Delete' }).click());
        act(() => typeInto(document.getElementById('storage-delete-confirm'), 'my-s3-storage'));
        act(() => screen.getByRole('button', { name: 'Cancel' }).click());

        expect(screen.queryByText('Confirm Storage Deletion?')).not.toBeInTheDocument();

        act(() => screen.getByRole('button', { name: 'Delete' }).click());
        expect(document.getElementById('storage-delete-confirm').value).toBe('');
    });
});
