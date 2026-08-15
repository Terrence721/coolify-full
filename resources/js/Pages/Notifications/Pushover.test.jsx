import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import Pushover from './Pushover';

vi.mock('@inertiajs/react', () => ({
    useForm: vi.fn((initialData) => {
        const data = { ...initialData };
        return {
            data,
            setData: (key, value) => {
                data[key] = value;
            },
            put: vi.fn(),
            processing: false,
            errors: {},
        };
    }),
    router: {
        post: vi.fn(),
    },
}));

describe('Notifications/Pushover', () => {
    const mockSettings = {
        pushover_enabled: true,
        pushover_user_key: 'user123',
        pushover_api_token: 'token456',
        deployment_success_pushover_notifications: true,
        deployment_failure_pushover_notifications: false,
        status_change_pushover_notifications: true,
        backup_success_pushover_notifications: false,
        backup_failure_pushover_notifications: false,
        scheduled_task_success_pushover_notifications: false,
        scheduled_task_failure_pushover_notifications: false,
        docker_cleanup_success_pushover_notifications: true,
        docker_cleanup_failure_pushover_notifications: false,
        server_disk_usage_pushover_notifications: true,
        server_reachable_pushover_notifications: false,
        server_unreachable_pushover_notifications: true,
        server_patch_pushover_notifications: false,
        traefik_outdated_pushover_notifications: false,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders heading and navigation tabs', () => {
        render(<Pushover settings={mockSettings} updateUrl="/notifications/pushover" sendTestUrl="/notifications/pushover/test" />);

        expect(screen.getByRole('heading', { level: 1, name: /Notifications/i })).toBeInTheDocument();
        expect(screen.getByText(/Get notified about your infrastructure\./)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Pushover/i })).toBeInTheDocument();
    });

    it('renders main Pushover settings form', () => {
        render(<Pushover settings={mockSettings} updateUrl="/notifications/pushover" sendTestUrl="/notifications/pushover/test" />);

        expect(screen.getByRole('heading', { level: 2, name: /Pushover/i })).toBeInTheDocument();
        const pushoverEnabledInput = document.getElementById('pushover_enabled');
        expect(pushoverEnabledInput).toBeInTheDocument();
        expect(screen.getByLabelText(/User Key/)).toBeInTheDocument();
        expect(screen.getByLabelText(/API Token/)).toBeInTheDocument();
    });

    it('renders Save and Send Test Notification buttons', () => {
        render(<Pushover settings={mockSettings} updateUrl="/notifications/pushover" sendTestUrl="/notifications/pushover/test" />);

        expect(screen.getByRole('button', { name: /Save/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Send Test Notification/i })).toBeInTheDocument();
    });

    it('renders all event notification groups', () => {
        render(<Pushover settings={mockSettings} updateUrl="/notifications/pushover" sendTestUrl="/notifications/pushover/test" />);

        expect(screen.getByText('Deployments')).toBeInTheDocument();
        expect(screen.getByText('Backups')).toBeInTheDocument();
        expect(screen.getByText('Scheduled Tasks')).toBeInTheDocument();
        const serverHeadings = screen.getAllByText('Server');
        expect(serverHeadings.length).toBeGreaterThan(0);
    });

    it('renders all event notification checkboxes', () => {
        render(<Pushover settings={mockSettings} updateUrl="/notifications/pushover" sendTestUrl="/notifications/pushover/test" />);

        expect(screen.getByLabelText(/Deployment Success/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Deployment Failure/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Container Status Changes/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Backup Success/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Docker Cleanup Success/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Server Disk Usage/)).toBeInTheDocument();
    });

    it('User Key and API Token fields are password type', () => {
        render(<Pushover settings={mockSettings} updateUrl="/notifications/pushover" sendTestUrl="/notifications/pushover/test" />);

        const userKeyInput = screen.getByLabelText(/User Key/);
        expect(userKeyInput).toHaveAttribute('type', 'password');

        const apiTokenInput = screen.getByLabelText(/API Token/);
        expect(apiTokenInput).toHaveAttribute('type', 'password');
    });

    it('Send Test Notification button is disabled when pushover is not enabled', () => {
        const disabledSettings = { ...mockSettings, pushover_enabled: false };

        render(<Pushover settings={disabledSettings} updateUrl="/notifications/pushover" sendTestUrl="/notifications/pushover/test" />);

        const testButton = screen.getByRole('button', { name: /Send Test Notification/i });
        expect(testButton).toBeDisabled();
    });

    it('Send Test Notification button is enabled when pushover is enabled', () => {
        const enabledSettings = { ...mockSettings, pushover_enabled: true };

        render(<Pushover settings={enabledSettings} updateUrl="/notifications/pushover" sendTestUrl="/notifications/pushover/test" />);

        const testButton = screen.getByRole('button', { name: /Send Test Notification/i });
        expect(testButton).not.toBeDisabled();
    });

    it('renders notification settings section', () => {
        render(<Pushover settings={mockSettings} updateUrl="/notifications/pushover" sendTestUrl="/notifications/pushover/test" />);

        expect(screen.getByText(/Notification Settings/)).toBeInTheDocument();
        expect(screen.getByText(/Select events for which you would like to receive Pushover notifications\./)).toBeInTheDocument();
    });
});
