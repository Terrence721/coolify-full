import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SettingsBackup from './SettingsBackup';

// The instance-wide backup settings page. Real logic: a 3-way conditional render driven by
// serverFunctional (localhost server not yet validated - a hard blocker, no form at all) and the
// derived showBackupPanels = Boolean(database) && Boolean(backup) (no database resource added
// yet vs. the full identity + BackupEditForm + BackupExecutionsList panels), plus the addDatabase
// button's guard on urls?.addDatabase. BackupEditForm and BackupExecutionsList are mocked out
// (both already have their own dedicated suites) - this suite stays focused on this page's own
// orchestration logic, matching the convention already used for the structurally similar
// Project/Service/DatabaseBackups.jsx.

const routerPostSpy = vi.fn();
const identityPutSpy = vi.fn();
const editFormSpy = vi.fn();
const executionsListSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => routerPostSpy(url, data, options),
    },
    useForm: (initial) => {
        let data = initial;
        return {
            get data() {
                return data;
            },
            setData: (key, value) => {
                data = { ...data, [key]: value };
            },
            put: (url) => identityPutSpy(url),
            processing: false,
            errors: {},
        };
    },
}));

vi.mock('../Components/BackupEditForm', () => ({
    default: (props) => {
        editFormSpy(props);
        return <div data-testid="backup-edit-form" />;
    },
}));

vi.mock('../Components/BackupExecutionsList', () => ({
    default: (props) => {
        executionsListSpy(props);
        return <div data-testid="backup-executions-list" />;
    },
}));

function baseProps(overrides = {}) {
    return {
        server: { uuid: 'server-1' },
        serverFunctional: true,
        database: { uuid: 'db-1', name: 'coolify-db', postgresUser: 'coolify', postgresPassword: 'secret', description: '' },
        backup: { id: 1, frequency: '0 0 * * *' },
        s3Storages: [{ id: 1, name: 'my-s3-storage' }],
        executions: [],
        executionsCount: 0,
        skip: 0,
        defaultTake: 5,
        currentPage: 1,
        showNext: false,
        showPrev: false,
        identityUpdateUrl: '/settings/backup/identity',
        urls: { addDatabase: '/settings/backup/add-database' },
        ...overrides,
    };
}

beforeEach(() => {
    routerPostSpy.mockClear();
    identityPutSpy.mockClear();
    editFormSpy.mockClear();
    executionsListSpy.mockClear();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('SettingsBackup', () => {
    it('shows the validation-required error state when serverFunctional is false, no form at all', () => {
        render(<SettingsBackup {...baseProps({ serverFunctional: false })} />);
        expect(screen.getByText(/Instance Backup is currently disabled/)).toBeInTheDocument();
        expect(document.querySelector('a[href="/server/server-1"]')).toBeInTheDocument();
        expect(screen.queryByTestId('backup-edit-form')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
    });

    it('shows the add-database prompt when serverFunctional but no database exists yet', () => {
        render(<SettingsBackup {...baseProps({ database: null })} />);
        expect(screen.getByText(/you first need to add a database resource/)).toBeInTheDocument();
        expect(screen.queryByTestId('backup-edit-form')).not.toBeInTheDocument();
    });

    it('shows the add-database prompt when database exists but backup does not', () => {
        // The derived showBackupPanels = Boolean(database) && Boolean(backup) - both must be
        // truthy, not just database. Locks in the AND, not an OR that would show a broken panel.
        render(<SettingsBackup {...baseProps({ backup: null })} />);
        expect(screen.getByText(/you first need to add a database resource/)).toBeInTheDocument();
        expect(screen.queryByTestId('backup-edit-form')).not.toBeInTheDocument();
    });

    it('"Configure Backup" posts to urls.addDatabase', () => {
        render(<SettingsBackup {...baseProps({ database: null })} />);
        act(() => screen.getByRole('button', { name: 'Configure Backup' }).click());
        expect(routerPostSpy).toHaveBeenCalledWith('/settings/backup/add-database', {}, { preserveScroll: true });
    });

    it('"Configure Backup" does nothing when urls.addDatabase is missing', () => {
        render(<SettingsBackup {...baseProps({ database: null, urls: {} })} />);
        act(() => screen.getByRole('button', { name: 'Configure Backup' }).click());
        expect(routerPostSpy).not.toHaveBeenCalled();
    });

    it('renders the identity form and both backup panels once database and backup both exist', () => {
        const props = baseProps();
        render(<SettingsBackup {...props} />);

        expect(screen.getByDisplayValue('db-1')).toBeInTheDocument();
        expect(screen.getByDisplayValue('coolify-db')).toBeInTheDocument();
        expect(screen.getByDisplayValue('coolify')).toBeInTheDocument();

        expect(screen.getByTestId('backup-edit-form')).toBeInTheDocument();
        expect(editFormSpy).toHaveBeenCalledWith(expect.objectContaining({ backup: props.backup, s3Storages: props.s3Storages, urls: props.urls }));

        expect(screen.getByTestId('backup-executions-list')).toBeInTheDocument();
        expect(executionsListSpy).toHaveBeenCalledWith(
            expect.objectContaining({ executions: props.executions, executionsCount: props.executionsCount, urls: props.urls }),
        );
    });

    it('the UUID/Name/User/Password fields are read-only, only Description is editable', () => {
        render(<SettingsBackup {...baseProps()} />);
        expect(screen.getByLabelText('UUID')).toHaveAttribute('readonly');
        expect(screen.getByLabelText('Name')).toHaveAttribute('readonly');
        expect(screen.getByLabelText('User')).toHaveAttribute('readonly');
        expect(screen.getByLabelText('Password')).toHaveAttribute('readonly');
        expect(screen.getByLabelText('Description')).not.toHaveAttribute('readonly');
    });

    it('Save submits the identity form to identityUpdateUrl', () => {
        render(<SettingsBackup {...baseProps()} />);
        act(() => screen.getByRole('button', { name: 'Save' }).click());
        expect(identityPutSpy).toHaveBeenCalledWith('/settings/backup/identity');
    });

    it('Save is only shown once both showBackupPanels and serverFunctional are true', () => {
        const { rerender } = render(<SettingsBackup {...baseProps({ database: null })} />);
        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();

        rerender(<SettingsBackup {...baseProps({ serverFunctional: false })} />);
        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
    });
});
