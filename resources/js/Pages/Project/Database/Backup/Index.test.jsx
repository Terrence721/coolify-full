import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Index from './Index';

// The standalone-database backups page, manually verified live end-to-end during the 2026-07-25
// database backups smoke test (issue #25, now closed at 19/19) - a real S3-enabled and a real
// plain-cron schedule both created via "+ Add", and a real cron execution updated a card's status
// badge and timing text from "No executions yet" to a real Success badge. DatabaseHeading and
// ConfigurationChecker are mocked out - both already have their own dedicated suites - keeping this
// suite focused on Index's own logic: the BackupCard status/timing/size rendering, the empty state,
// the canUpdate gate on "+ Add", and the CreateBackupModal wiring.

const dbHeadingSpy = vi.fn();
const configCheckerSpy = vi.fn();
const createModalSpy = vi.fn();

vi.mock('../../../../Components/DatabaseHeading', () => ({
    default: (props) => {
        dbHeadingSpy(props);
        return <div data-testid="database-heading" />;
    },
}));

vi.mock('../../../../Components/ConfigurationChecker', () => ({
    default: (props) => {
        configCheckerSpy(props);
        return <div data-testid="configuration-checker" />;
    },
}));

vi.mock('../../../../Components/CreateBackupModal', () => ({
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
        executeUrl: '/backups/backup-1',
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        heading: { parameters: {}, dockerCleanupDefault: true, isFunctional: true, isExited: false },
        configurationChecker: { isConfigurationChanged: false, isExited: false, configHash: 'abc', diff: [] },
        scheduledBackups: [],
        s3Storages: [{ id: 1, name: 'my-s3-storage' }],
        canUpdate: true,
        urls: { store: '/backups', start: '/start', stop: '/stop', restart: '/restart', checkStatus: '/check-status' },
        ...overrides,
    };
}

beforeEach(() => {
    dbHeadingSpy.mockClear();
    configCheckerSpy.mockClear();
    createModalSpy.mockClear();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('Project/Database/Backup/Index', () => {
    it('renders DatabaseHeading and ConfigurationChecker with the heading/configurationChecker/urls props', () => {
        const props = baseProps();
        render(<Index {...props} />);
        expect(screen.getByTestId('database-heading')).toBeInTheDocument();
        expect(screen.getByTestId('configuration-checker')).toBeInTheDocument();
        expect(dbHeadingSpy).toHaveBeenCalledWith(expect.objectContaining({ heading: props.heading, urls: props.urls }));
        expect(configCheckerSpy).toHaveBeenCalledWith(expect.objectContaining({ configurationChecker: props.configurationChecker }));
    });

    it('shows "No scheduled backups configured." when there are none', () => {
        render(<Index {...baseProps({ scheduledBackups: [] })} />);
        expect(screen.getByText('No scheduled backups configured.')).toBeInTheDocument();
    });

    it('renders a card with "No executions yet" and no size/S3 text for a never-run backup', () => {
        render(<Index {...baseProps({ scheduledBackups: [backup({ status: null })] })} />);
        expect(screen.getByText('No executions yet')).toBeInTheDocument();
        expect(screen.getByText('Last Run: Never • Total Executions: 0')).toBeInTheDocument();
        expect(document.querySelector('a[href="/backups/backup-1"]')).toBeInTheDocument();
    });

    it('maps each status to the right label, and shows size + S3 text once a run has happened', () => {
        const cases = [
            { status: 'running', label: 'In Progress' },
            { status: 'failed', label: 'Failed' },
            { status: 'success', label: 'Success' },
        ];
        for (const { status, label } of cases) {
            const { unmount } = render(
                <Index
                    {...baseProps({
                        scheduledBackups: [
                            backup({ status, timingText: '2 minutes ago (00m 05s) • Jul 25, 12:00', sizeText: '1.03 KB', saveS3: true }),
                        ],
                    })}
                />,
            );
            expect(screen.getByText(label)).toBeInTheDocument();
            expect(screen.getByText(/2 minutes ago.*Size: 1\.03 KB.*S3: Enabled/)).toBeInTheDocument();
            unmount();
        }
    });

    it('falls back to the raw status string for an unrecognized status', () => {
        render(<Index {...baseProps({ scheduledBackups: [backup({ status: 'weird_status' })] })} />);
        expect(screen.getByText('weird_status')).toBeInTheDocument();
    });

    it('shows the "+ Add" button only when canUpdate is true', () => {
        const { unmount } = render(<Index {...baseProps({ canUpdate: true })} />);
        expect(screen.getByRole('button', { name: '+ Add' })).toBeInTheDocument();
        unmount();

        render(<Index {...baseProps({ canUpdate: false })} />);
        expect(screen.queryByRole('button', { name: '+ Add' })).not.toBeInTheDocument();
    });

    it('opens and closes CreateBackupModal via the "+ Add" button, passing storeUrl and s3Storages through', () => {
        const props = baseProps();
        render(<Index {...props} />);
        expect(screen.queryByTestId('create-backup-modal')).not.toBeInTheDocument();

        act(() => screen.getByRole('button', { name: '+ Add' }).click());
        expect(screen.getByTestId('create-backup-modal')).toBeInTheDocument();
        expect(createModalSpy).toHaveBeenCalledWith(expect.objectContaining({ open: true, storeUrl: '/backups', s3Storages: props.s3Storages }));

        act(() => screen.getByRole('button', { name: 'Close Modal' }).click());
        expect(screen.queryByTestId('create-backup-modal')).not.toBeInTheDocument();
    });

    it('renders multiple backups as separate cards, most-recent-first order as given by props', () => {
        render(
            <Index
                {...baseProps({
                    scheduledBackups: [
                        backup({ id: 1, frequency: '0 0 * * *', executeUrl: '/backups/1' }),
                        backup({ id: 2, frequency: '*/5 * * * *', executeUrl: '/backups/2' }),
                    ],
                })}
            />,
        );
        expect(screen.getByText('0 0 * * *')).toBeInTheDocument();
        expect(screen.getByText('*/5 * * * *')).toBeInTheDocument();
        expect(document.querySelector('a[href="/backups/1"]')).toBeInTheDocument();
        expect(document.querySelector('a[href="/backups/2"]')).toBeInTheDocument();
    });
});
