import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DatabaseBackups from './DatabaseBackups';

// The service-database backups page, live-verified end-to-end during the 2026-07-25 database
// backups smoke test (issue #25, now closed at 19/19) against a real throwaway service deployed
// via the actual gitea-with-postgresql template: Nav tabs, Restart with real streaming log
// output, both a plain-cron and an S3-enabled schedule created correctly, and - the part that
// makes this page's architecture genuinely different from its standalone-database sibling
// (Project/Database/Backup/Index.jsx) - selecting a card sets a ?selectedBackupId= query param
// and shows an inline BackupEditForm + execution list on the *same* page, rather than
// navigating to a separate route. ServiceHeading, ConfigurationChecker, BackupEditForm,
// BackupExecutionsList, and CreateBackupModal are all mocked out (ConfigurationChecker and
// BackupEditForm already have their own dedicated suites) - this suite stays focused on this
// page's own logic: the inline BackupCard status/timing/successRate rendering, the
// needsCustomType gate, the query-param-driven select/deselect wiring, and the modal.

const routerGetSpy = vi.fn();
const postSpy = vi.fn();
const headingSpy = vi.fn();
const configCheckerSpy = vi.fn();
const editFormSpy = vi.fn();
const executionsListSpy = vi.fn();
const createModalSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        get: (url, data, options) => routerGetSpy(url, data, options),
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
            post: (url, options) => postSpy(url, options),
            processing: false,
        };
    },
}));

vi.mock('../../../Components/ServiceHeading', () => ({
    default: (props) => {
        headingSpy(props);
        return <div data-testid="service-heading" />;
    },
}));

vi.mock('../../../Components/ConfigurationChecker', () => ({
    default: (props) => {
        configCheckerSpy(props);
        return <div data-testid="configuration-checker" />;
    },
}));

vi.mock('../../../Components/BackupEditForm', () => ({
    default: (props) => {
        editFormSpy(props);
        return <div data-testid="backup-edit-form" />;
    },
}));

vi.mock('../../../Components/BackupExecutionsList', () => ({
    default: (props) => {
        executionsListSpy(props);
        return <div data-testid="backup-executions-list" />;
    },
}));

vi.mock('../../../Components/CreateBackupModal', () => ({
    default: (props) => {
        createModalSpy(props);
        return props.open ? (
            <div data-testid="create-backup-modal">
                <button type="button" onClick={props.onClose}>
                    Close Modal
                </button>
            </div>
        ) : null;
    },
}));

function backup(overrides = {}) {
    return {
        id: 1,
        frequency: '0 0 * * *',
        saveS3: false,
        status: null,
        timingText: null,
        sizeText: null,
        totalExecutions: 0,
        successRate: null,
        selected: false,
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        service: {},
        configurationChecker: { isConfigurationChanged: false },
        needsCustomType: false,
        scheduledBackups: [],
        selectedBackup: null,
        s3Storages: [{ id: 1, name: 'my-s3-storage' }],
        executions: [],
        executionsCount: 0,
        skip: 0,
        defaultTake: 5,
        currentPage: 1,
        showNext: false,
        showPrev: false,
        parameters: { project_uuid: 'proj-1', environment_uuid: 'env-1', service_uuid: 'svc-1', stack_service_uuid: 'stack-1' },
        urls: { store: '/backups/store' },
        setTypeUrl: '/backups/set-type',
        ...overrides,
    };
}

beforeEach(() => {
    routerGetSpy.mockClear();
    postSpy.mockClear();
    headingSpy.mockClear();
    configCheckerSpy.mockClear();
    editFormSpy.mockClear();
    executionsListSpy.mockClear();
    createModalSpy.mockClear();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('Project/Service/DatabaseBackups', () => {
    it('renders ServiceHeading and ConfigurationChecker with the right props', () => {
        const props = baseProps();
        render(<DatabaseBackups {...props} />);
        expect(screen.getByTestId('service-heading')).toBeInTheDocument();
        expect(screen.getByTestId('configuration-checker')).toBeInTheDocument();
        expect(headingSpy).toHaveBeenCalledWith(expect.objectContaining({ service: props.service, urls: props.urls }));
        expect(configCheckerSpy).toHaveBeenCalledWith(expect.objectContaining({ configurationChecker: props.configurationChecker }));
    });

    it('renders the sidebar nav links built from parameters', () => {
        render(<DatabaseBackups {...baseProps()} />);
        expect(document.querySelector('a[href="/project/proj-1/environment/env-1/service/svc-1"]')).toBeInTheDocument();
        expect(document.querySelector('a[href="/project/proj-1/environment/env-1/service/svc-1/stack-1"]')).toBeInTheDocument();
        expect(document.querySelector('a[href="/project/proj-1/environment/env-1/service/svc-1/stack-1/advanced"]')).toBeInTheDocument();
        expect(document.querySelector('a[href="/project/proj-1/environment/env-1/service/svc-1/stack-1/backups"]')).toBeInTheDocument();
    });

    it('shows SetTypeForm instead of the backups list when needsCustomType is true', () => {
        render(<DatabaseBackups {...baseProps({ needsCustomType: true })} />);
        expect(screen.getByText(/Select the type of database/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: '+ Add' })).not.toBeInTheDocument();
    });

    it('SetTypeForm submits the selected type to setTypeUrl', () => {
        render(<DatabaseBackups {...baseProps({ needsCustomType: true })} />);

        act(() => {
            screen.getByLabelText('Type').value = 'postgresql';
            screen.getByLabelText('Type').dispatchEvent(new Event('change', { bubbles: true }));
        });
        act(() => screen.getByRole('button', { name: 'Set' }).click());

        expect(postSpy).toHaveBeenCalledWith('/backups/set-type', { preserveScroll: true });
    });

    it('shows "No scheduled backups configured." when there are none', () => {
        render(<DatabaseBackups {...baseProps({ scheduledBackups: [] })} />);
        expect(screen.getByText('No scheduled backups configured.')).toBeInTheDocument();
    });

    it('renders a card with "No executions yet" for a never-run backup', () => {
        render(<DatabaseBackups {...baseProps({ scheduledBackups: [backup()] })} />);
        expect(screen.getByText('No executions yet')).toBeInTheDocument();
        expect(screen.getByText(/Last Run: Never/)).toBeInTheDocument();
    });

    it('maps each status to the right label once a run has happened', () => {
        const cases = [
            { status: 'running', label: 'In Progress' },
            { status: 'failed', label: 'Failed' },
            { status: 'success', label: 'Success' },
        ];
        for (const { status, label } of cases) {
            const { unmount } = render(
                <DatabaseBackups
                    {...baseProps({
                        scheduledBackups: [backup({ status, timingText: '2 minutes ago', sizeText: '1.03 KB', saveS3: true })],
                    })}
                />,
            );
            expect(screen.getByText(label)).toBeInTheDocument();
            expect(screen.getByText(/2 minutes ago.*Size: 1\.03 KB.*S3: Enabled/)).toBeInTheDocument();
            unmount();
        }
    });

    it('colors the success rate green/orange/red at the 80/50 thresholds', () => {
        const cases = [
            { successRate: 90, expectedClass: 'text-green-600' },
            { successRate: 60, expectedClass: 'text-warning-600' },
            { successRate: 20, expectedClass: 'text-red-600' },
        ];
        for (const { successRate, expectedClass } of cases) {
            const { unmount } = render(
                <DatabaseBackups
                    {...baseProps({
                        scheduledBackups: [backup({ status: 'success', timingText: 'just now', successRate, totalExecutions: 5 })],
                    })}
                />,
            );
            expect(screen.getByText(`${successRate}%`)).toHaveClass(expectedClass);
            unmount();
        }
    });

    it('clicking a card calls router.get with selectedBackupId set and skip removed', () => {
        render(<DatabaseBackups {...baseProps({ scheduledBackups: [backup({ id: 42 })] })} />);

        act(() => screen.getByText('0 0 * * * Backup').click());

        expect(routerGetSpy).toHaveBeenCalled();
        const [url] = routerGetSpy.mock.calls[0];
        expect(url).toContain('selectedBackupId=42');
        expect(url).not.toContain('skip=');
    });

    it('does not render BackupEditForm/BackupExecutionsList when nothing is selected', () => {
        render(<DatabaseBackups {...baseProps({ selectedBackup: null })} />);
        expect(screen.queryByTestId('backup-edit-form')).not.toBeInTheDocument();
        expect(screen.queryByTestId('backup-executions-list')).not.toBeInTheDocument();
    });

    it('renders BackupEditForm and BackupExecutionsList with the right props once a backup is selected', () => {
        const props = baseProps({ selectedBackup: backup({ id: 42 }), executions: [{ id: 1 }], executionsCount: 1 });
        render(<DatabaseBackups {...props} />);

        expect(screen.getByTestId('backup-edit-form')).toBeInTheDocument();
        expect(editFormSpy).toHaveBeenCalledWith(
            expect.objectContaining({ backup: props.selectedBackup, s3Storages: props.s3Storages, urls: props.urls }),
        );
        expect(screen.getByTestId('backup-executions-list')).toBeInTheDocument();
        expect(executionsListSpy).toHaveBeenCalledWith(
            expect.objectContaining({ executions: props.executions, executionsCount: 1, urls: props.urls }),
        );
    });

    it('opens and closes CreateBackupModal via "+ Add", passing storeUrl and s3Storages through', () => {
        const props = baseProps();
        render(<DatabaseBackups {...props} />);
        expect(screen.queryByTestId('create-backup-modal')).not.toBeInTheDocument();

        act(() => screen.getByRole('button', { name: '+ Add' }).click());
        expect(screen.getByTestId('create-backup-modal')).toBeInTheDocument();
        expect(createModalSpy).toHaveBeenCalledWith(
            expect.objectContaining({ open: true, storeUrl: '/backups/store', s3Storages: props.s3Storages }),
        );

        act(() => screen.getByRole('button', { name: 'Close Modal' }).click());
        expect(screen.queryByTestId('create-backup-modal')).not.toBeInTheDocument();
    });
});
