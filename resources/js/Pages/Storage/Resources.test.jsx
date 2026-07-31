import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Resources from './Resources';

// The `/storages/{uuid}/resources` page, live-verified end-to-end during the 2026-07-23 Storage
// smoke test (issue #25) against the real MinIO instance in this dev stack: a real attached backup
// schedule listed correctly, the search filter genuinely narrows the table, "Disable S3" gates on
// a real window.confirm() and correctly flips save_s3 to false when accepted (verified via
// tinker). This suite locks that in as automated coverage; the page itself was previously entirely
// untested.

const postSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => postSpy(url, data, options),
    },
}));

function baseProps(overrides = {}) {
    return {
        storage: { id: 's3-main', name: 'Main S3', isUsable: true },
        backups: [],
        allStorages: [{ id: 's3-main', name: 'Main S3', isUsable: true }],
        canUpdate: true,
        showUrl: '/storages/s3-main',
        resourcesUrl: '/storages/s3-main/resources',
        ...overrides,
    };
}

function backup(overrides = {}) {
    return {
        id: 1,
        databaseName: 'production-db',
        frequency: '0 0 * * *',
        enabled: true,
        resourceLink: '/project/p1/environment/e1/database/db1',
        backupLink: '/project/p1/environment/e1/database/db1/backup/1',
        moveBackupUrl: '/backup/1/move',
        disableS3Url: '/backup/1/disable-s3',
        ...overrides,
    };
}

describe('Storage/Resources', () => {
    beforeEach(() => {
        postSpy.mockClear();
        vi.spyOn(window, 'confirm').mockReturnValue(true);
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows "No backup schedules are using this storage." when there are none', () => {
        render(<Resources {...baseProps()} />);
        expect(screen.getByText('No backup schedules are using this storage.')).toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });

    it('shows the Usable/Not Usable badge based on storage.isUsable', () => {
        const { rerender } = render(<Resources {...baseProps({ storage: { id: 's3-main', name: 'Main S3', isUsable: true } })} />);
        expect(screen.getByText('Usable')).toBeInTheDocument();

        rerender(<Resources {...baseProps({ storage: { id: 's3-main', name: 'Main S3', isUsable: false } })} />);
        expect(screen.getByText('Not Usable')).toBeInTheDocument();
    });

    it('renders the General/Resources nav links with the given URLs', () => {
        render(<Resources {...baseProps()} />);
        expect(screen.getByRole('link', { name: 'General' })).toHaveAttribute('href', '/storages/s3-main');
        expect(screen.getByRole('link', { name: 'Resources' })).toHaveAttribute('href', '/storages/s3-main/resources');
    });

    it('renders a row per backup with database/frequency/status/S3 columns', () => {
        render(
            <Resources
                {...baseProps({
                    backups: [backup(), backup({ id: 2, databaseName: 'staging-db', frequency: '0 12 * * *', enabled: false })],
                })}
            />,
        );

        expect(screen.getByRole('link', { name: 'production-db' })).toHaveAttribute('href', backup().resourceLink);
        expect(screen.getByRole('link', { name: '0 0 * * *' })).toHaveAttribute('href', backup().backupLink);
        expect(screen.getByText('Enabled')).toBeInTheDocument();

        expect(screen.getByText('staging-db')).toBeInTheDocument();
        expect(screen.getByText('Disabled')).toBeInTheDocument();
    });

    it('renders plain text instead of a link when resourceLink/backupLink are absent', () => {
        render(<Resources {...baseProps({ backups: [backup({ resourceLink: null, backupLink: null })] })} />);

        expect(screen.getByText('production-db')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'production-db' })).not.toBeInTheDocument();
    });

    it('filters the table by database name or frequency, case-insensitively', () => {
        render(
            <Resources
                {...baseProps({
                    backups: [backup(), backup({ id: 2, databaseName: 'staging-db', frequency: '0 12 * * *' })],
                })}
            />,
        );

        fireEvent.change(screen.getByPlaceholderText('Search resources...'), { target: { value: 'STAGING' } });
        expect(screen.getByText('staging-db')).toBeInTheDocument();
        expect(screen.queryByText('production-db')).not.toBeInTheDocument();

        fireEvent.change(screen.getByPlaceholderText('Search resources...'), { target: { value: '0 0' } });
        expect(screen.getByText('production-db')).toBeInTheDocument();
        expect(screen.queryByText('staging-db')).not.toBeInTheDocument();
    });

    it('hides Save/Disable S3 and disables the storage picker when canUpdate is false', () => {
        render(<Resources {...baseProps({ canUpdate: false, backups: [backup()] })} />);

        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Disable S3' })).not.toBeInTheDocument();
        expect(screen.getByRole('combobox')).toBeDisabled();
    });

    it('lists every storage as an option, disabling unusable ones with an "(unusable)" suffix', () => {
        render(
            <Resources
                {...baseProps({
                    backups: [backup()],
                    allStorages: [
                        { id: 's3-main', name: 'Main S3', isUsable: true },
                        { id: 's3-broken', name: 'Broken S3', isUsable: false },
                    ],
                })}
            />,
        );

        expect(screen.getByRole('option', { name: 'Main S3' })).not.toBeDisabled();
        expect(screen.getByRole('option', { name: 'Broken S3 (unusable)' })).toBeDisabled();
    });

    it('moves the backup via router.post(moveBackupUrl, {new_storage_id}) using the selected storage', () => {
        render(
            <Resources
                {...baseProps({
                    backups: [backup()],
                    allStorages: [
                        { id: 's3-main', name: 'Main S3', isUsable: true },
                        { id: 's3-other', name: 'Other S3', isUsable: true },
                    ],
                })}
            />,
        );

        fireEvent.change(screen.getByRole('combobox'), { target: { value: 's3-other' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(postSpy).toHaveBeenCalledWith('/backup/1/move', { new_storage_id: 's3-other' }, { preserveScroll: true });
    });

    it('disables S3 via router.post(disableS3Url) only after confirming the window.confirm() prompt', () => {
        window.confirm.mockReturnValueOnce(false);
        render(<Resources {...baseProps({ backups: [backup()] })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Disable S3' }));
        expect(postSpy).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Disable S3' }));
        expect(postSpy).toHaveBeenCalledWith('/backup/1/disable-s3', {}, { preserveScroll: true });
    });
});
