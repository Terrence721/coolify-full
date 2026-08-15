import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import Discord from './Discord';

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

describe('Notifications/Discord', () => {
    const mockSettings = {
        discord_enabled: true,
        discord_ping_enabled: false,
        discord_webhook_url: 'https://discord.com/api/webhooks/123/abc',
        deployment_success_discord_notifications: true,
        deployment_failure_discord_notifications: false,
        status_change_discord_notifications: true,
        backup_success_discord_notifications: false,
        backup_failure_discord_notifications: false,
        scheduled_task_success_discord_notifications: false,
        scheduled_task_failure_discord_notifications: false,
        docker_cleanup_success_discord_notifications: true,
        docker_cleanup_failure_discord_notifications: false,
        server_disk_usage_discord_notifications: true,
        server_reachable_discord_notifications: false,
        server_unreachable_discord_notifications: true,
        server_patch_discord_notifications: false,
        traefik_outdated_discord_notifications: false,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders heading and navigation tabs', () => {
        render(<Discord settings={mockSettings} updateUrl="/notifications/discord" sendTestUrl="/notifications/discord/test" />);

        expect(screen.getByRole('heading', { level: 1, name: /Notifications/i })).toBeInTheDocument();
        expect(screen.getByText(/Get notified about your infrastructure\./)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Discord/i })).toBeInTheDocument();
    });

    it('renders main Discord settings form', () => {
        render(<Discord settings={mockSettings} updateUrl="/notifications/discord" sendTestUrl="/notifications/discord/test" />);

        expect(screen.getByRole('heading', { level: 2, name: /Discord/i })).toBeInTheDocument();
        const discordEnabledInput = document.getElementById('discord_enabled');
        expect(discordEnabledInput).toBeInTheDocument();
        const pingEnabledInput = document.getElementById('discord_ping_enabled');
        expect(pingEnabledInput).toBeInTheDocument();
        expect(screen.getByLabelText(/Webhook/)).toBeInTheDocument();
    });

    it('renders Save and Send Test Notification buttons', () => {
        render(<Discord settings={mockSettings} updateUrl="/notifications/discord" sendTestUrl="/notifications/discord/test" />);

        expect(screen.getByRole('button', { name: /Save/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Send Test Notification/i })).toBeInTheDocument();
    });

    it('renders all event notification groups', () => {
        render(<Discord settings={mockSettings} updateUrl="/notifications/discord" sendTestUrl="/notifications/discord/test" />);

        expect(screen.getByText('Deployments')).toBeInTheDocument();
        expect(screen.getByText('Backups')).toBeInTheDocument();
        expect(screen.getByText('Scheduled Tasks')).toBeInTheDocument();
        const serverHeadings = screen.getAllByText('Server');
        expect(serverHeadings.length).toBeGreaterThan(0);
    });

    it('renders all event notification checkboxes', () => {
        render(<Discord settings={mockSettings} updateUrl="/notifications/discord" sendTestUrl="/notifications/discord/test" />);

        expect(screen.getByLabelText(/Deployment Success/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Deployment Failure/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Container Status Changes/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Backup Success/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Docker Cleanup Success/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Server Disk Usage/)).toBeInTheDocument();
    });

    it('webhook field is password type', () => {
        render(<Discord settings={mockSettings} updateUrl="/notifications/discord" sendTestUrl="/notifications/discord/test" />);

        const webhookInput = screen.getByLabelText(/Webhook/);
        expect(webhookInput).toHaveAttribute('type', 'password');
    });

    it('Send Test Notification button is disabled when discord is not enabled', () => {
        const disabledSettings = { ...mockSettings, discord_enabled: false };

        render(<Discord settings={disabledSettings} updateUrl="/notifications/discord" sendTestUrl="/notifications/discord/test" />);

        const testButton = screen.getByRole('button', { name: /Send Test Notification/i });
        expect(testButton).toBeDisabled();
    });

    it('Send Test Notification button is enabled when discord is enabled', () => {
        const enabledSettings = { ...mockSettings, discord_enabled: true };

        render(<Discord settings={enabledSettings} updateUrl="/notifications/discord" sendTestUrl="/notifications/discord/test" />);

        const testButton = screen.getByRole('button', { name: /Send Test Notification/i });
        expect(testButton).not.toBeDisabled();
    });

    it('renders notification settings section', () => {
        render(<Discord settings={mockSettings} updateUrl="/notifications/discord" sendTestUrl="/notifications/discord/test" />);

        expect(screen.getByText(/Notification Settings/)).toBeInTheDocument();
        expect(screen.getByText(/Select events for which you would like to receive Discord notifications\./)).toBeInTheDocument();
    });
});
