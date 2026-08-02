import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import ExecutionCard from './ExecutionCard';

// A single backup execution card, rendered by BackupExecutionsList (which mocks this out - this
// is its own suite). Real logic: a 4-way status-text mapping with a nested S3-warning branch on
// top of "success", status-based border styling with an unknown-status fallback, a Download link
// shown only for a successful execution, and a delete-confirmation modal whose actions text,
// confirmation-text target, and S3-deletion checkbox all vary based on the execution's own
// localStorageDeleted/s3Uploaded/s3StorageDeleted state.

vi.mock('./PasswordConfirmModal', () => ({
    default: ({ title, action, actions, checkboxes, confirmationText, onClose }) => (
        <div data-testid="PasswordConfirmModal">
            <div>{title}</div>
            <div>{JSON.stringify(action)}</div>
            <div>{JSON.stringify(actions)}</div>
            <div>{JSON.stringify(checkboxes)}</div>
            <div>confirmationText:{confirmationText}</div>
            <button type="button" onClick={onClose}>
                close-modal
            </button>
        </div>
    ),
}));

function execution(overrides = {}) {
    return {
        id: 1,
        status: 'success',
        timingText: '2 hours ago',
        databaseName: 'appdb',
        size: '12 MB',
        filename: 'backups/appdb-2026-08-02.sql',
        localStorageDeleted: false,
        s3Uploaded: undefined,
        s3StorageDeleted: false,
        downloadUrl: '/download/backup/1',
        destroyUrl: '/backups/1',
        ...overrides,
    };
}

describe('status text', () => {
    it('shows "Success" for a plain successful execution', () => {
        render(<ExecutionCard execution={execution()} />);
        expect(screen.getByText('Success')).toBeInTheDocument();
    });

    it('shows "Success (S3 Warning)" when s3Uploaded is explicitly false', () => {
        render(<ExecutionCard execution={execution({ s3Uploaded: false })} />);
        expect(screen.getByText('Success (S3 Warning)')).toBeInTheDocument();
    });

    it('shows plain "Success" when s3Uploaded is true (uploaded successfully)', () => {
        render(<ExecutionCard execution={execution({ s3Uploaded: true })} />);
        expect(screen.getByText('Success')).toBeInTheDocument();
    });

    it('shows "In Progress" for a running execution', () => {
        render(<ExecutionCard execution={execution({ status: 'running' })} />);
        expect(screen.getByText('In Progress')).toBeInTheDocument();
    });

    it('shows "Failed" for a failed execution', () => {
        render(<ExecutionCard execution={execution({ status: 'failed' })} />);
        expect(screen.getByText('Failed')).toBeInTheDocument();
    });

    it('falls back to the raw status for an unrecognized value', () => {
        render(<ExecutionCard execution={execution({ status: 'queued' })} />);
        expect(screen.getByText('queued')).toBeInTheDocument();
    });
});

describe('Download link', () => {
    it('shows Download only for a successful execution', () => {
        const { rerender } = render(<ExecutionCard execution={execution({ status: 'success' })} />);
        expect(screen.getByRole('link', { name: 'Download' })).toHaveAttribute('href', '/download/backup/1');

        rerender(<ExecutionCard execution={execution({ status: 'running' })} />);
        expect(screen.queryByRole('link', { name: 'Download' })).not.toBeInTheDocument();
    });
});

it('shows local storage availability based on localStorageDeleted', () => {
    const { rerender } = render(<ExecutionCard execution={execution({ localStorageDeleted: false })} />);
    expect(screen.getByText('Local Storage: available')).toBeInTheDocument();

    rerender(<ExecutionCard execution={execution({ localStorageDeleted: true })} />);
    expect(screen.getByText('Local Storage: deleted')).toBeInTheDocument();
});

it('shows the message block only when execution.message is present', () => {
    const { rerender } = render(<ExecutionCard execution={execution({ message: undefined })} />);
    expect(screen.queryByText('Boom')).not.toBeInTheDocument();

    rerender(<ExecutionCard execution={execution({ message: 'Boom' })} />);
    expect(screen.getByText('Boom')).toBeInTheDocument();
});

describe('delete confirmation modal', () => {
    it('opens from the Delete button and closes via onClose', () => {
        render(<ExecutionCard execution={execution()} />);
        expect(screen.queryByTestId('PasswordConfirmModal')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
        expect(screen.getByTestId('PasswordConfirmModal')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'close-modal' }));
        expect(screen.queryByTestId('PasswordConfirmModal')).not.toBeInTheDocument();
    });

    it('wires the delete action to destroyUrl and confirmationText to the filename', () => {
        render(<ExecutionCard execution={execution()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));

        const modal = screen.getByTestId('PasswordConfirmModal');
        expect(modal).toHaveTextContent('"method":"delete","url":"/backups/1"');
        expect(modal).toHaveTextContent('confirmationText:backups/appdb-2026-08-02.sql');
    });

    it('describes a record-only deletion when local storage is already deleted', () => {
        render(<ExecutionCard execution={execution({ localStorageDeleted: true })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));

        expect(screen.getByTestId('PasswordConfirmModal')).toHaveTextContent('This backup execution record will be deleted.');
    });

    it('describes a permanent local deletion when local storage is not yet deleted', () => {
        render(<ExecutionCard execution={execution({ localStorageDeleted: false })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));

        expect(screen.getByTestId('PasswordConfirmModal')).toHaveTextContent('This backup will be permanently deleted from local storage.');
    });

    it('offers the S3-deletion checkbox only when uploaded to S3 and not already deleted there', () => {
        render(<ExecutionCard execution={execution({ s3Uploaded: true, s3StorageDeleted: false })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));

        expect(screen.getByTestId('PasswordConfirmModal')).toHaveTextContent('delete_backup_s3');
    });

    it('hides the S3-deletion checkbox when already deleted from S3', () => {
        render(<ExecutionCard execution={execution({ s3Uploaded: true, s3StorageDeleted: true })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));

        const modal = screen.getByTestId('PasswordConfirmModal');
        expect(modal).not.toHaveTextContent('delete_backup_s3');
    });
});
