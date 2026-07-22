import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DatabaseImportTab from './DatabaseImportTab';

// Covers the chunked-upload logic directly (CSRF token, chunk math, the dzuuid fixed in the
// js/insecure-randomness CodeQL finding staying consistent across chunks, progress reporting,
// error handling), the dumpAll command-swap toggle, the S3-vs-file restore payload shape fed to
// PasswordConfirmModal, and the useTeamChannel reload wiring - none of it previously tested.

let teamChannelCallback = null;
const reloadSpy = vi.fn();
const postSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        reload: (opts) => reloadSpy(opts),
        post: (url, data, options) => postSpy(url, data, options),
    },
}));

vi.mock('../hooks/useTeamChannel', () => ({
    useTeamChannel: (events, onEvent) => {
        teamChannelCallback = onEvent;
    },
}));

function baseImportTab(overrides = {}) {
    return {
        unsupported: false,
        running: true,
        commands: { default: 'pg_restore', dumpAll: null, dumpAllSuffix: null },
        s3Storages: [],
        canUpdate: true,
        dbType: 'standalone-postgresql',
        urls: {
            upload: '/import/upload',
            checkFile: '/import/check-file',
            checkS3: '/import/check-s3',
            run: '/import/run',
            restoreS3: '/import/restore-s3',
        },
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        importTab: baseImportTab(overrides.importTab),
        flash: overrides.flash ?? {},
    };
}

beforeEach(() => {
    reloadSpy.mockClear();
    postSpy.mockClear();
    teamChannelCallback = null;
    document.head.innerHTML = '<meta name="csrf-token" content="test-csrf-token">';
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('DatabaseImportTab', () => {
    it('shows an unsupported message and nothing else when unsupported is true', () => {
        render(<DatabaseImportTab {...baseProps({ importTab: { unsupported: true } })} />);

        expect(screen.getByText('Database restore is not supported.')).toBeInTheDocument();
        expect(screen.queryByText('Choose Restore Method')).not.toBeInTheDocument();
    });

    it('shows a not-running message when the database is not running', () => {
        render(<DatabaseImportTab {...baseProps({ importTab: { running: false } })} />);

        expect(screen.getByText('Database must be running to restore a backup.')).toBeInTheDocument();
    });

    it('only shows the S3 restore tile when s3Storages is non-empty', () => {
        const { rerender } = render(<DatabaseImportTab {...baseProps({ importTab: { s3Storages: [] } })} />);
        expect(screen.queryByText('Restore from S3')).not.toBeInTheDocument();

        rerender(<DatabaseImportTab {...baseProps({ importTab: { s3Storages: [{ id: 1, name: 'my-bucket' }] } })} />);
        expect(screen.getByText('Restore from S3')).toBeInTheDocument();
    });

    it('toggling "Backup includes all databases" swaps the restore command to the dumpAll variant and back', () => {
        render(
            <DatabaseImportTab
                {...baseProps({ importTab: { commands: { default: 'pg_restore', dumpAll: 'pg_restore --all', dumpAllSuffix: ' --clean' } } })}
            />,
        );

        expect(screen.getByDisplayValue('pg_restore')).toBeInTheDocument();

        act(() => screen.getByLabelText('Backup includes all databases').click());
        expect(screen.getByDisplayValue('pg_restore --all --clean')).toBeInTheDocument();

        act(() => screen.getByLabelText('Backup includes all databases').click());
        expect(screen.getByDisplayValue('pg_restore')).toBeInTheDocument();
    });

    it('uploads a small file as a single chunk, including the CSRF token and a real dzuuid', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({}) }));
        render(<DatabaseImportTab {...baseProps()} />);

        act(() => screen.getByText('Restore from File').click());
        const file = new File(['small backup content'], 'backup.sql', { type: 'application/sql' });
        const input = document.getElementById('db-import-file');
        await act(async () => {
            Object.defineProperty(input, 'files', { value: [file] });
            input.dispatchEvent(new Event('change', { bubbles: true }));
            await Promise.resolve();
            await Promise.resolve();
        });

        expect(global.fetch).toHaveBeenCalledTimes(1);
        const [url, options] = global.fetch.mock.calls[0];
        expect(url).toBe('/import/upload');
        expect(options.body.get('_token')).toBe('test-csrf-token');
        expect(options.body.get('dzuuid')).toMatch(/^[0-9a-f-]{36}$/);
        expect(options.body.get('dztotalchunkcount')).toBe('1');

        expect(await screen.findByText(/backup.sql/)).toBeInTheDocument();
    });

    it('splits a file larger than the chunk size into multiple chunks sharing the same dzuuid', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({}) }));
        render(<DatabaseImportTab {...baseProps()} />);
        act(() => screen.getByText('Restore from File').click());

        const bigFile = new File([new Uint8Array(12_000_000)], 'big-backup.sql');
        const input = document.getElementById('db-import-file');
        await act(async () => {
            Object.defineProperty(input, 'files', { value: [bigFile] });
            input.dispatchEvent(new Event('change', { bubbles: true }));
            await Promise.resolve();
            await Promise.resolve();
            await Promise.resolve();
        });

        expect(global.fetch).toHaveBeenCalledTimes(2);
        const firstBody = global.fetch.mock.calls[0][1].body;
        const secondBody = global.fetch.mock.calls[1][1].body;
        expect(firstBody.get('dzchunkindex')).toBe('0');
        expect(secondBody.get('dzchunkindex')).toBe('1');
        expect(firstBody.get('dzuuid')).toBe(secondBody.get('dzuuid'));
        expect(secondBody.get('dztotalchunkcount')).toBe('2');
    });

    it('shows the server-provided error and clears progress when a chunk upload fails', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 422, json: () => Promise.resolve({ error: 'File type not allowed.' }) }));
        render(<DatabaseImportTab {...baseProps()} />);
        act(() => screen.getByText('Restore from File').click());

        const file = new File(['bad content'], 'backup.exe');
        const input = document.getElementById('db-import-file');
        await act(async () => {
            Object.defineProperty(input, 'files', { value: [file] });
            input.dispatchEvent(new Event('change', { bubbles: true }));
            await Promise.resolve();
            await Promise.resolve();
        });

        expect(await screen.findByText('File type not allowed.')).toBeInTheDocument();
        expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
    });

    it('keeps "Check File" disabled until a custom location is entered, then posts it', () => {
        render(<DatabaseImportTab {...baseProps()} />);
        act(() => screen.getByText('Restore from File').click());

        const checkButton = screen.getByRole('button', { name: 'Check File' });
        expect(checkButton).toBeDisabled();

        const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        const locationInput = screen.getByPlaceholderText('e.g. /home/user/backup.sql.gz');
        act(() => {
            setter.call(locationInput, '/srv/backup.sql.gz');
            locationInput.dispatchEvent(new Event('input', { bubbles: true }));
        });
        expect(checkButton).not.toBeDisabled();

        act(() => checkButton.click());
        expect(postSpy).toHaveBeenCalledWith(
            '/import/check-file',
            { customLocation: '/srv/backup.sql.gz' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('only marks S3 as checked when the response flash reports success', () => {
        render(<DatabaseImportTab {...baseProps({ importTab: { s3Storages: [{ id: 1, name: 'bucket-1' }] } })} />);
        act(() => screen.getByText('Restore from S3').click());

        const setter = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype, 'value').set;
        act(() => {
            setter.call(screen.getByLabelText('S3 Storage'), '1');
            screen.getByLabelText('S3 Storage').dispatchEvent(new Event('change', { bubbles: true }));
        });
        const inputSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        act(() => {
            inputSetter.call(screen.getByPlaceholderText('/backups/database-backup.gz'), '/backups/db.gz');
            screen.getByPlaceholderText('/backups/database-backup.gz').dispatchEvent(new Event('input', { bubbles: true }));
        });

        act(() => screen.getByRole('button', { name: 'Check File' }).click());
        expect(postSpy).toHaveBeenCalled();
        const onSuccess = postSpy.mock.calls[0][2].onSuccess;

        expect(screen.queryByText('File Information')).not.toBeInTheDocument();
        act(() => onSuccess({ props: { flash: {} } }));
        expect(screen.queryByText('File Information')).not.toBeInTheDocument();

        act(() => onSuccess({ props: { flash: { success: true } } }));
        expect(screen.getByText('File Information')).toBeInTheDocument();
    });

    it('feeds PasswordConfirmModal the S3 payload shape when restoring from S3', () => {
        render(<DatabaseImportTab {...baseProps({ importTab: { s3Storages: [{ id: 1, name: 'bucket-1' }] } })} />);
        act(() => screen.getByText('Restore from S3').click());

        const setter = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype, 'value').set;
        act(() => {
            setter.call(screen.getByLabelText('S3 Storage'), '1');
            screen.getByLabelText('S3 Storage').dispatchEvent(new Event('change', { bubbles: true }));
        });
        const inputSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        act(() => {
            inputSetter.call(screen.getByPlaceholderText('/backups/database-backup.gz'), '/backups/db.gz');
            screen.getByPlaceholderText('/backups/database-backup.gz').dispatchEvent(new Event('input', { bubbles: true }));
        });
        act(() => screen.getByRole('button', { name: 'Check File' }).click());
        const onSuccess = postSpy.mock.calls[0][2].onSuccess;
        act(() => onSuccess({ props: { flash: { success: true } } }));

        act(() => screen.getByRole('button', { name: 'Restore Database from S3' }).click());

        expect(screen.getByText('Restore Database from S3?')).toBeInTheDocument();
        expect(screen.getByText('Download backup from S3 storage')).toBeInTheDocument();
    });

    it('syncs the activity log id from a matching database-import flash context', () => {
        const { rerender } = render(<DatabaseImportTab {...baseProps({ flash: {} })} />);
        expect(screen.queryByText('Database Restore Output')).not.toBeInTheDocument();

        rerender(<DatabaseImportTab {...baseProps({ flash: { activityContext: 'database-import', activityId: 'act-123' } })} />);
        expect(screen.getByText('Database Restore Output')).toBeInTheDocument();
    });

    it('reloads just the importTab prop when a ServiceChecked/ServiceStatusChanged team event fires', () => {
        render(<DatabaseImportTab {...baseProps()} />);

        expect(teamChannelCallback).toBeInstanceOf(Function);
        act(() => teamChannelCallback());

        expect(reloadSpy).toHaveBeenCalledWith({ only: ['importTab'], preserveScroll: true });
    });
});
