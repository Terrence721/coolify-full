import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import Telegram from './Telegram';

// The Telegram notifications settings page, the richest of the 5 remaining Notifications
// siblings (Discord/Slack/Pushover/Webhook share the same shape but only a toggle checkbox per
// event) - each event row here has both an enable checkbox AND a "Custom Thread ID" text field,
// so there are two independently-keyed fields per row instead of one. Real risk: with 14 event
// rows each rendering 2 fields off the same EVENT_GROUPS array, it's easy to get a row's checkbox
// and its thread-ID field cross-wired (writing to the wrong underlying key) without it being
// visually obvious - same class of bug as SettingsOauth's by-index provider update.

const putSpy = vi.fn();
const postSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            put: (url) => putSpy(url),
            processing: false,
        };
    },
    router: { post: (url) => postSpy(url) },
}));

function baseSettings(overrides = {}) {
    return {
        telegram_enabled: false,
        telegram_token: '',
        telegram_chat_id: '',
        deployment_success_telegram_notifications: false,
        telegram_notifications_deployment_success_thread_id: '',
        deployment_failure_telegram_notifications: false,
        telegram_notifications_deployment_failure_thread_id: '',
        status_change_telegram_notifications: false,
        telegram_notifications_status_change_thread_id: '',
        backup_success_telegram_notifications: false,
        telegram_notifications_backup_success_thread_id: '',
        backup_failure_telegram_notifications: false,
        telegram_notifications_backup_failure_thread_id: '',
        scheduled_task_success_telegram_notifications: false,
        telegram_notifications_scheduled_task_success_thread_id: '',
        scheduled_task_failure_telegram_notifications: false,
        telegram_notifications_scheduled_task_failure_thread_id: '',
        docker_cleanup_success_telegram_notifications: false,
        telegram_notifications_docker_cleanup_success_thread_id: '',
        docker_cleanup_failure_telegram_notifications: false,
        telegram_notifications_docker_cleanup_failure_thread_id: '',
        server_disk_usage_telegram_notifications: false,
        telegram_notifications_server_disk_usage_thread_id: '',
        server_reachable_telegram_notifications: false,
        telegram_notifications_server_reachable_thread_id: '',
        server_unreachable_telegram_notifications: false,
        telegram_notifications_server_unreachable_thread_id: '',
        server_patch_telegram_notifications: false,
        telegram_notifications_server_patch_thread_id: '',
        traefik_outdated_telegram_notifications: false,
        telegram_notifications_traefik_outdated_thread_id: '',
        ...overrides,
    };
}

const BASE_PROPS = {
    updateUrl: '/notifications/telegram',
    sendTestUrl: '/notifications/telegram/send-test',
};

afterEach(() => {
    putSpy.mockClear();
    postSpy.mockClear();
});

it('renders the token/chat ID fields as masked and submits the whole form on Save', () => {
    render(<Telegram settings={baseSettings({ telegram_token: 'bot-token', telegram_chat_id: '12345' })} {...BASE_PROPS} />);

    expect(screen.getByLabelText(/Bot API Token/)).toHaveAttribute('type', 'password');
    expect(screen.getByLabelText(/Chat ID/)).toHaveAttribute('type', 'password');

    screen.getByRole('button', { name: 'Save' }).click();

    expect(putSpy).toHaveBeenCalledWith('/notifications/telegram');
});

it('disables the Send Test Notification button until telegram_enabled is checked', () => {
    // Two separate render() calls, not rerender() - the mocked useForm's useState(initial) only
    // reads its initial value on first mount, so rerender() with a changed settings prop
    // wouldn't actually update the form's internal state.
    render(<Telegram settings={baseSettings({ telegram_enabled: false })} {...BASE_PROPS} />);
    expect(screen.getByRole('button', { name: 'Send Test Notification' })).toBeDisabled();
});

it('enables the Send Test Notification button once telegram_enabled is true', () => {
    render(<Telegram settings={baseSettings({ telegram_enabled: true })} {...BASE_PROPS} />);
    expect(screen.getByRole('button', { name: 'Send Test Notification' })).toBeEnabled();
});

it('calls router.post with sendTestUrl when Send Test Notification is clicked', () => {
    render(<Telegram settings={baseSettings({ telegram_enabled: true })} {...BASE_PROPS} />);

    screen.getByRole('button', { name: 'Send Test Notification' }).click();

    expect(postSpy).toHaveBeenCalledWith('/notifications/telegram/send-test');
});

it("toggling one event's checkbox only updates that event's own field, not a neighboring row's", () => {
    render(<Telegram settings={baseSettings()} {...BASE_PROPS} />);

    // Deliberately not the first field in its group (Backup Success) - a bug that always wrote
    // to a group's first field would pass a same-as-first-field test by coincidence.
    screen.getByLabelText('Backup Failure').click();

    expect(screen.getByLabelText('Backup Failure')).toBeChecked();
    expect(screen.getByLabelText('Backup Success')).not.toBeChecked();
    expect(screen.getByLabelText('Deployment Success')).not.toBeChecked();
});

it("typing into one event's thread-ID field only writes to that event's own field, not a neighboring row's", () => {
    render(<Telegram settings={baseSettings()} {...BASE_PROPS} />);

    const threadFields = screen.getAllByPlaceholderText('Custom Telegram Thread ID');
    // Backup Failure is the 5th event row (Deployments group has 3, Backup Success is the 4th) -
    // deliberately not a group's first field, same reasoning as the checkbox test above.
    fireEvent.change(threadFields[4], { target: { value: '999' } });

    expect(threadFields[4]).toHaveValue('999');
    expect(threadFields[0]).toHaveValue('');
    expect(threadFields[3]).toHaveValue('');
});

it('renders all 4 sibling notification-provider nav links plus the current Telegram tab', () => {
    render(<Telegram settings={baseSettings()} {...BASE_PROPS} />);

    expect(screen.getByRole('button', { name: 'Email' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Discord' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Slack' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Pushover' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Webhook' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Telegram' })).toBeInTheDocument();
});
