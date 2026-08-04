import { render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CreateBackupModal from './CreateBackupModal';

// The "New Scheduled Backup" modal. Real logic: the S3 storage select is only shown (and only
// makes sense) when "Save to S3?" is checked, s3_storage_id is seeded from the first available
// S3 storage rather than a plain blank default, and handleClose() must reset()/clearErrors()
// before calling onClose - since the component's own `if (!open) return null` early return keeps
// the same mounted instance (and its useForm state) alive across a close/reopen cycle rather than
// unmounting it, a dropped reset() would leak a previously-typed value into the next open.
// Previously entirely untested.

const postSpy = vi.fn();
const resetSpy = vi.fn();
const clearErrorsSpy = vi.fn();
let mockErrors = {};

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            post: (url, options) => {
                postSpy(url, data, options);
                options?.onSuccess?.();
            },
            processing: false,
            errors: mockErrors,
            reset: () => {
                resetSpy();
                setDataState(initial);
            },
            clearErrors: clearErrorsSpy,
        };
    },
}));

function s3Storages() {
    return [
        { id: 1, name: 'primary-bucket' },
        { id: 2, name: 'secondary-bucket' },
    ];
}

function baseProps(overrides = {}) {
    return {
        open: true,
        onClose: vi.fn(),
        storeUrl: '/backups',
        s3Storages: s3Storages(),
        ...overrides,
    };
}

describe('CreateBackupModal', () => {
    beforeEach(() => {
        postSpy.mockClear();
        resetSpy.mockClear();
        clearErrorsSpy.mockClear();
        mockErrors = {};
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('renders nothing when open is false', () => {
        render(<CreateBackupModal {...baseProps({ open: false })} />);
        expect(screen.queryByText('New Scheduled Backup')).not.toBeInTheDocument();
    });

    it('seeds s3_storage_id from the first available S3 storage', () => {
        render(<CreateBackupModal {...baseProps()} />);

        act(() => document.getElementById('create-backup-save-to-s3').click());

        expect(document.getElementById('create-backup-s3-storage-id').value).toBe('1');
    });

    it('seeds s3_storage_id to blank when there are no S3 storages', () => {
        render(<CreateBackupModal {...baseProps({ s3Storages: [] })} />);

        act(() => document.getElementById('create-backup-save-to-s3').click());

        expect(document.getElementById('create-backup-s3-storage-id').value).toBe('');
    });

    it('hides the S3 storage select until "Save to S3?" is checked', () => {
        render(<CreateBackupModal {...baseProps()} />);

        expect(screen.queryByText('S3 Storage')).not.toBeInTheDocument();

        act(() => document.getElementById('create-backup-save-to-s3').click());

        expect(screen.getByText('S3 Storage')).toBeInTheDocument();
    });

    it('lists every S3 storage as an option', () => {
        render(<CreateBackupModal {...baseProps()} />);
        act(() => document.getElementById('create-backup-save-to-s3').click());

        const select = document.getElementById('create-backup-s3-storage-id');
        const options = Array.from(select.options).map((o) => o.textContent);

        expect(options).toEqual(['Choose an S3 storage...', 'primary-bucket', 'secondary-bucket']);
    });

    it('submits via post(storeUrl) with the current form data, closing on success', () => {
        const onClose = vi.fn();
        render(<CreateBackupModal {...baseProps({ onClose })} />);

        const frequencyInput = document.getElementById('create-backup-frequency');
        const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        act(() => {
            setter.call(frequencyInput, '@daily');
            frequencyInput.dispatchEvent(new Event('input', { bubbles: true }));
        });
        act(() => screen.getByRole('button', { name: 'Save' }).click());

        expect(postSpy).toHaveBeenCalledWith(
            '/backups',
            expect.objectContaining({ frequency: '@daily', save_to_s3: false }),
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('resets the form before calling onClose, so a typed value does not leak into the next open', () => {
        const onClose = vi.fn();
        const { rerender } = render(<CreateBackupModal {...baseProps({ onClose })} />);

        const frequencyInput = document.getElementById('create-backup-frequency');
        const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        act(() => {
            setter.call(frequencyInput, '@weekly');
            frequencyInput.dispatchEvent(new Event('input', { bubbles: true }));
        });

        act(() => screen.getByRole('button', { name: '✕' }).click());
        expect(onClose).toHaveBeenCalledTimes(1);

        rerender(<CreateBackupModal {...baseProps({ open: false, onClose })} />);
        rerender(<CreateBackupModal {...baseProps({ open: true, onClose })} />);

        expect(document.getElementById('create-backup-frequency').value).toBe('');
    });

    it('closes via the backdrop click too, also resetting the form', () => {
        const onClose = vi.fn();
        render(<CreateBackupModal {...baseProps({ onClose })} />);

        const backdrop = document.querySelector('.absolute.inset-0.h-full.w-full');
        act(() => backdrop.click());

        expect(onClose).toHaveBeenCalledTimes(1);
        expect(resetSpy).toHaveBeenCalled();
    });

    it('renders a per-field error message when present', () => {
        mockErrors = { frequency: 'The frequency field is required.' };
        render(<CreateBackupModal {...baseProps()} />);

        expect(screen.getByText('The frequency field is required.')).toBeInTheDocument();
    });
});
