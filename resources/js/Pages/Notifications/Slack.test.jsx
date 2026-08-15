import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import Slack from './Slack';

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

describe('Notifications/Slack', () => {
    const mockSettings = {
        slack_enabled: true,
        slack_webhook_url: 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXX',
        deployment_success_slack_notifications: true,
        deployment_failure_slack_notifications: false,
        status_change_slack_notifications: true,
        backup_success_slack_notifications: false,
        backup_failure_slack_notifications: false,
        scheduled_task_success_slack_notifications: false,
        scheduled_task_failure_slack_notifications: false,
        docker_cleanup_success_slack_notifications: true,
        docker_cleanup_failure_slack_notifications: false,
        server_disk_usage_slack_notifications: true,
        server_reachable_slack_notifications: false,
        server_unreachable_slack_notifications: true,
        server_patch_slack_notifications: false,
        traefik_outdated_slack_notifications: false,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders heading and navigation tabs', () => {
        render(<Slack settings={mockSettings} updateUrl="/notifications/slack" sendTestUrl="/notifications/slack/test" />);

        expect(screen.getByRole('heading', { level: 1, name: /Notifications/i })).toBeInTheDocument();
        expect(screen.getByText(/Get notified about your infrastructure\./)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Slack/i })).toBeInTheDocument();
    });

    it('renders main Slack settings form', () => {
        render(<Slack settings={mockSettings} updateUrl="/notifications/slack" sendTestUrl="/notifications/slack/test" />);

        expect(screen.getByRole('heading', { level: 2, name: /Slack/i })).toBeInTheDocument();
        const slackEnabledInput = document.getElementById('slack_enabled');
        expect(slackEnabledInput).toBeInTheDocument();
        expect(screen.getByLabelText(/Webhook/)).toBeInTheDocument();
    });

    it('renders Save and Send Test Notification buttons', () => {
        render(<Slack settings={mockSettings} updateUrl="/notifications/slack" sendTestUrl="/notifications/slack/test" />);

        expect(screen.getByRole('button', { name: /Save/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Send Test Notification/i })).toBeInTheDocument();
    });

    it('renders all event notification groups', () => {
        render(<Slack settings={mockSettings} updateUrl="/notifications/slack" sendTestUrl="/notifications/slack/test" />);

        expect(screen.getByText('Deployments')).toBeInTheDocument();
        expect(screen.getByText('Backups')).toBeInTheDocument();
        expect(screen.getByText('Scheduled Tasks')).toBeInTheDocument();
        const serverHeadings = screen.getAllByText('Server');
        expect(serverHeadings.length).toBeGreaterThan(0);
    });

    it('renders all event notification checkboxes', () => {
        render(<Slack settings={mockSettings} updateUrl="/notifications/slack" sendTestUrl="/notifications/slack/test" />);

        expect(screen.getByLabelText(/Deployment Success/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Deployment Failure/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Container Status Changes/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Backup Success/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Docker Cleanup Success/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Server Disk Usage/)).toBeInTheDocument();
    });

    it('webhook field is password type', () => {
        render(<Slack settings={mockSettings} updateUrl="/notifications/slack" sendTestUrl="/notifications/slack/test" />);

        const webhookInput = screen.getByLabelText(/Webhook/);
        expect(webhookInput).toHaveAttribute('type', 'password');
    });

    it('Send Test Notification button is disabled when slack is not enabled', () => {
        const disabledSettings = { ...mockSettings, slack_enabled: false };

        render(<Slack settings={disabledSettings} updateUrl="/notifications/slack" sendTestUrl="/notifications/slack/test" />);

        const testButton = screen.getByRole('button', { name: /Send Test Notification/i });
        expect(testButton).toBeDisabled();
    });

    it('Send Test Notification button is enabled when slack is enabled', () => {
        const enabledSettings = { ...mockSettings, slack_enabled: true };

        render(<Slack settings={enabledSettings} updateUrl="/notifications/slack" sendTestUrl="/notifications/slack/test" />);

        const testButton = screen.getByRole('button', { name: /Send Test Notification/i });
        expect(testButton).not.toBeDisabled();
    });

    it('renders notification settings section', () => {
        render(<Slack settings={mockSettings} updateUrl="/notifications/slack" sendTestUrl="/notifications/slack/test" />);

        expect(screen.getByText(/Notification Settings/)).toBeInTheDocument();
        expect(screen.getByText(/Select events for which you would like to receive Slack notifications\./)).toBeInTheDocument();
    });
});
