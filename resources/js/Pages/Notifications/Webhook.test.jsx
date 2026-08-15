import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import Webhook from './Webhook';

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

describe('Notifications/Webhook', () => {
    const mockSettings = {
        webhook_enabled: true,
        webhook_url: 'https://example.com/webhook',
        deployment_success_webhook_notifications: true,
        deployment_failure_webhook_notifications: false,
        status_change_webhook_notifications: true,
        backup_success_webhook_notifications: false,
        backup_failure_webhook_notifications: false,
        scheduled_task_success_webhook_notifications: false,
        scheduled_task_failure_webhook_notifications: false,
        docker_cleanup_success_webhook_notifications: true,
        docker_cleanup_failure_webhook_notifications: false,
        server_disk_usage_webhook_notifications: true,
        server_reachable_webhook_notifications: false,
        server_unreachable_webhook_notifications: true,
        server_patch_webhook_notifications: false,
        traefik_outdated_webhook_notifications: false,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders heading and navigation tabs', () => {
        render(
            <Webhook settings={mockSettings} updateUrl="/notifications/webhook" sendTestUrl="/notifications/webhook/test" />
        );

        expect(screen.getByRole('heading', { level: 1, name: /Notifications/i })).toBeInTheDocument();
        expect(screen.getByText(/Get notified about your infrastructure\./)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Webhook/i })).toBeInTheDocument();
    });

    it('renders main Webhook settings form', () => {
        render(
            <Webhook settings={mockSettings} updateUrl="/notifications/webhook" sendTestUrl="/notifications/webhook/test" />
        );

        expect(screen.getByRole('heading', { level: 2, name: /Webhook/i })).toBeInTheDocument();
        const webhookEnabledInput = document.getElementById('webhook_enabled');
        expect(webhookEnabledInput).toBeInTheDocument();
        expect(screen.getByLabelText(/Webhook URL/)).toBeInTheDocument();
    });

    it('renders Save and Send Test Notification buttons', () => {
        render(
            <Webhook settings={mockSettings} updateUrl="/notifications/webhook" sendTestUrl="/notifications/webhook/test" />
        );

        expect(screen.getByRole('button', { name: /Save/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Send Test Notification/i })).toBeInTheDocument();
    });

    it('renders all event notification groups', () => {
        render(
            <Webhook settings={mockSettings} updateUrl="/notifications/webhook" sendTestUrl="/notifications/webhook/test" />
        );

        expect(screen.getByText('Deployments')).toBeInTheDocument();
        expect(screen.getByText('Backups')).toBeInTheDocument();
        expect(screen.getByText('Scheduled Tasks')).toBeInTheDocument();
        const serverHeadings = screen.getAllByText('Server');
        expect(serverHeadings.length).toBeGreaterThan(0);
    });

    it('renders all event notification checkboxes', () => {
        render(
            <Webhook settings={mockSettings} updateUrl="/notifications/webhook" sendTestUrl="/notifications/webhook/test" />
        );

        expect(screen.getByLabelText(/Deployment Success/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Deployment Failure/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Container Status Changes/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Backup Success/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Docker Cleanup Success/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Server Disk Usage/)).toBeInTheDocument();
    });

    it('webhook URL field is password type', () => {
        render(
            <Webhook settings={mockSettings} updateUrl="/notifications/webhook" sendTestUrl="/notifications/webhook/test" />
        );

        const webhookUrlInput = screen.getByLabelText(/Webhook URL/);
        expect(webhookUrlInput).toHaveAttribute('type', 'password');
    });

    it('Send Test Notification button is disabled when webhook is not enabled', () => {
        const disabledSettings = { ...mockSettings, webhook_enabled: false };

        render(
            <Webhook settings={disabledSettings} updateUrl="/notifications/webhook" sendTestUrl="/notifications/webhook/test" />
        );

        const testButton = screen.getByRole('button', { name: /Send Test Notification/i });
        expect(testButton).toBeDisabled();
    });

    it('Send Test Notification button is enabled when webhook is enabled', () => {
        const enabledSettings = { ...mockSettings, webhook_enabled: true };

        render(
            <Webhook settings={enabledSettings} updateUrl="/notifications/webhook" sendTestUrl="/notifications/webhook/test" />
        );

        const testButton = screen.getByRole('button', { name: /Send Test Notification/i });
        expect(testButton).not.toBeDisabled();
    });

    it('renders notification settings section', () => {
        render(
            <Webhook settings={mockSettings} updateUrl="/notifications/webhook" sendTestUrl="/notifications/webhook/test" />
        );

        expect(screen.getByText(/Notification Settings/)).toBeInTheDocument();
        expect(
            screen.getByText(/Select events for which you would like to receive webhook notifications\./)
        ).toBeInTheDocument();
    });
});
