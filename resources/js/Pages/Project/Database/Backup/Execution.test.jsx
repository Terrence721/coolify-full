import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import Execution from './Execution';

vi.mock('../../../../Components/BackupEditForm', () => ({
    default: ({ backup }) => <div data-testid="backup-edit-form">Backup Edit Form - {backup?.schedule_name}</div>,
}));

vi.mock('../../../../Components/BackupExecutionsList', () => ({
    default: ({ executionsCount, currentPage }) => (
        <div data-testid="backup-executions-list">
            Backup Executions List - {executionsCount} total - Page {currentPage}
        </div>
    ),
}));

vi.mock('../../../../Components/ConfigurationChecker', () => ({
    default: ({ configurationChecker }) => <div data-testid="configuration-checker">Configuration Checker - {configurationChecker?.name}</div>,
}));

vi.mock('../../../../Components/DatabaseHeading', () => ({
    default: ({ heading }) => <div data-testid="database-heading">Database Heading - {heading?.database_name}</div>,
}));

describe('Project/Database/Backup/Execution', () => {
    const mockProps = {
        heading: {
            id: '1',
            database_name: 'test-db',
            database_type: 'postgres',
        },
        configurationChecker: {
            name: 'S3 Config',
            status: 'ok',
        },
        backup: {
            id: '1',
            schedule_name: 'Daily Backup',
            frequency: 'daily',
        },
        s3Storages: [
            { id: '1', name: 'Storage 1' },
            { id: '2', name: 'Storage 2' },
        ],
        executions: [
            { id: '1', status: 'success' },
            { id: '2', status: 'failed' },
        ],
        executionsCount: 2,
        skip: 0,
        defaultTake: 10,
        currentPage: 1,
        showNext: false,
        showPrev: false,
        urls: {
            update: '/backup/update',
            delete: '/backup/delete',
            start: '/backup/start',
        },
    };

    it('renders main heading', () => {
        render(<Execution {...mockProps} />);

        expect(screen.getByRole('heading', { level: 1, name: /Backups/i })).toBeInTheDocument();
    });

    it('renders ConfigurationChecker component', () => {
        render(<Execution {...mockProps} />);

        expect(screen.getByTestId('configuration-checker')).toBeInTheDocument();
        expect(screen.getByText(/S3 Config/)).toBeInTheDocument();
    });

    it('renders DatabaseHeading component', () => {
        render(<Execution {...mockProps} />);

        expect(screen.getByTestId('database-heading')).toBeInTheDocument();
        expect(screen.getByText(/test-db/)).toBeInTheDocument();
    });

    it('renders BackupEditForm component', () => {
        render(<Execution {...mockProps} />);

        expect(screen.getByTestId('backup-edit-form')).toBeInTheDocument();
        expect(screen.getByText(/Daily Backup/)).toBeInTheDocument();
    });

    it('renders BackupExecutionsList component', () => {
        render(<Execution {...mockProps} />);

        expect(screen.getByTestId('backup-executions-list')).toBeInTheDocument();
        expect(screen.getByText(/2 total/)).toBeInTheDocument();
        expect(screen.getByText(/Page 1/)).toBeInTheDocument();
    });

    it('passes correct props to all sub-components', () => {
        const { rerender } = render(<Execution {...mockProps} />);

        expect(screen.getByText(/S3 Config/)).toBeInTheDocument();
        expect(screen.getByText(/test-db/)).toBeInTheDocument();
        expect(screen.getByText(/Daily Backup/)).toBeInTheDocument();
        expect(screen.getByText(/2 total/)).toBeInTheDocument();

        const updatedProps = {
            ...mockProps,
            currentPage: 2,
            executionsCount: 50,
        };

        rerender(<Execution {...updatedProps} />);

        expect(screen.getByText(/50 total/)).toBeInTheDocument();
        expect(screen.getByText(/Page 2/)).toBeInTheDocument();
    });

    it('renders with empty executions', () => {
        const emptyProps = {
            ...mockProps,
            executions: [],
            executionsCount: 0,
        };

        render(<Execution {...emptyProps} />);

        expect(screen.getByTestId('backup-executions-list')).toBeInTheDocument();
        expect(screen.getByText(/0 total/)).toBeInTheDocument();
    });
});
