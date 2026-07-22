import { render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import BackupEditForm from './BackupEditForm';

// Covers the database-type-driven field switching (dump_all + databases-to-backup only for the
// MySQL family, a differently-labeled field for Mongo, neither for anything else), the S3
// checkbox dependency chain (disable_local_backup only enabled once save_s3 is checked, S3
// storage picker only rendered once save_s3 is checked), the Backup Now/Delete button gates,
// and the delete confirmation - unlike most PasswordConfirmModal call sites, this one keeps the
// password requirement AND adds two checkboxes, previously untested.

const putSpy = vi.fn();
const postSpy = vi.fn();
const deleteSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => postSpy(url, data, options),
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

vi.mock('./PasswordConfirmModal', () => ({
    default: ({ title, actions, checkboxes, confirmationLabel, action, onClose }) => (
        <div role="dialog" aria-label={title}>
            <p>{title}</p>
            {actions.map((a) => (
                <p key={a}>{a}</p>
            ))}
            {checkboxes.map((cb) => (
                <label key={cb.id}>{cb.label}</label>
            ))}
            <p>{confirmationLabel}</p>
            <p data-testid="delete-action">{JSON.stringify(action)}</p>
            <button type="button" onClick={onClose}>
                Cancel
            </button>
        </div>
    ),
}));

function baseBackup(overrides = {}) {
    return {
        databaseId: 5,
        databaseName: 'orders-db',
        databaseType: 'App\\Models\\StandalonePostgresql',
        enabled: true,
        frequency: '0 0 * * *',
        timeout: 3600,
        saveS3: false,
        disableLocalBackup: false,
        s3StorageId: null,
        databasesToBackup: '',
        dumpAll: false,
        databaseBackupRetentionAmountLocally: 5,
        databaseBackupRetentionDaysLocally: 7,
        databaseBackupRetentionMaxStorageLocally: 0,
        databaseBackupRetentionAmountS3: 5,
        databaseBackupRetentionDaysS3: 7,
        databaseBackupRetentionMaxStorageS3: 0,
        timezone: 'UTC',
        status: 'idle',
        ...overrides,
    };
}

function baseUrls(overrides = {}) {
    return { update: '/backup/1', backupNow: '/backup/1/run', destroy: '/backup/1', ...overrides };
}

beforeEach(() => {
    putSpy.mockClear();
    postSpy.mockClear();
    deleteSpy.mockClear();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('BackupEditForm', () => {
    it('shows the dump-all toggle and databases-to-backup field for MySQL-family databases', () => {
        render(<BackupEditForm backup={baseBackup({ databaseType: 'App\\Models\\StandaloneMysql' })} s3Storages={[]} urls={baseUrls()} />);

        expect(screen.getByLabelText('Backup All Databases')).toBeInTheDocument();
        expect(screen.getByLabelText('Databases To Backup')).toBeInTheDocument();
    });

    it('shows a differently-labeled field, with no dump-all toggle, for Mongo', () => {
        render(<BackupEditForm backup={baseBackup({ databaseType: 'App\\Models\\StandaloneMongodb' })} s3Storages={[]} urls={baseUrls()} />);

        expect(screen.queryByLabelText('Backup All Databases')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Databases To Include')).toBeInTheDocument();
    });

    it('hides both the dump-all toggle and any databases-to-backup field for other database types', () => {
        render(<BackupEditForm backup={baseBackup({ databaseType: 'App\\Models\\StandaloneRedis' })} s3Storages={[]} urls={baseUrls()} />);

        expect(screen.queryByLabelText('Backup All Databases')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Databases To Backup')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Databases To Include')).not.toBeInTheDocument();
    });

    it('disables S3 Enabled when there are no validated S3 storages', () => {
        render(<BackupEditForm backup={baseBackup()} s3Storages={[]} urls={baseUrls()} />);
        expect(screen.getByLabelText(/S3 Enabled/)).toBeDisabled();
    });

    it('only enables Disable Local Backup and shows the S3 storage picker once S3 is enabled', () => {
        render(<BackupEditForm backup={baseBackup()} s3Storages={[{ id: 1, name: 'my-bucket' }]} urls={baseUrls()} />);

        expect(screen.getByLabelText('Disable Local Backup')).toBeDisabled();
        expect(screen.queryByText('S3 Storage')).not.toBeInTheDocument();

        act(() => screen.getByLabelText(/S3 Enabled/).click());

        expect(screen.getByLabelText('Disable Local Backup')).not.toBeDisabled();
        expect(screen.getByText('S3 Storage')).toBeInTheDocument();
    });

    it('only shows Backup Now when the backup status is currently running', () => {
        const { rerender } = render(<BackupEditForm backup={baseBackup({ status: 'idle' })} s3Storages={[]} urls={baseUrls()} />);
        expect(screen.queryByRole('button', { name: 'Backup Now' })).not.toBeInTheDocument();

        rerender(<BackupEditForm backup={baseBackup({ status: 'running' })} s3Storages={[]} urls={baseUrls()} />);
        expect(screen.getByRole('button', { name: 'Backup Now' })).toBeInTheDocument();

        act(() => screen.getByRole('button', { name: 'Backup Now' }).click());
        expect(postSpy).toHaveBeenCalledWith('/backup/1/run', {}, expect.objectContaining({ preserveScroll: true }));
    });

    it('only shows the delete button for a real, saved database (databaseId !== 0)', () => {
        const { rerender } = render(<BackupEditForm backup={baseBackup({ databaseId: 0 })} s3Storages={[]} urls={baseUrls()} />);
        expect(screen.queryByRole('button', { name: 'Delete Backups and Schedule' })).not.toBeInTheDocument();

        rerender(<BackupEditForm backup={baseBackup({ databaseId: 5 })} s3Storages={[]} urls={baseUrls()} />);
        expect(screen.getByRole('button', { name: 'Delete Backups and Schedule' })).toBeInTheDocument();
    });

    it('submits the form via put to urls.update', () => {
        render(<BackupEditForm backup={baseBackup()} s3Storages={[]} urls={baseUrls()} />);

        act(() => screen.getByRole('button', { name: 'Save' }).click());
        expect(putSpy).toHaveBeenCalledWith('/backup/1', expect.any(Object), expect.objectContaining({ preserveScroll: true }));
    });

    it('opens the delete confirmation with both retention checkboxes and the database name as the confirmation text', () => {
        render(<BackupEditForm backup={baseBackup({ databaseName: 'orders-db' })} s3Storages={[]} urls={baseUrls()} />);

        act(() => screen.getByRole('button', { name: 'Delete Backups and Schedule' }).click());

        expect(screen.getByRole('dialog', { name: 'Confirm Backup Schedule Deletion?' })).toBeInTheDocument();
        expect(screen.getByText('All backups will be permanently deleted from local storage.')).toBeInTheDocument();
        expect(screen.getByText(/deleted \(associated with this backup job\) from the selected S3 Storage/)).toBeInTheDocument();
        expect(screen.getByText(/Database Name/)).toBeInTheDocument();
        expect(screen.getByTestId('delete-action')).toHaveTextContent('/backup/1');
    });
});
