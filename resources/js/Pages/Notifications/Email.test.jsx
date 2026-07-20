import { render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import Email from './Email';

// Regression coverage for a real bug found via manual smoke-test QA: the "Use system wide
// (transactional) email settings" checkbox only updated local form state, with no save call -
// the original Livewire component had this exact field wired to an instant-save. Without it, a
// user could uncheck it, fill in and successfully save SMTP/Resend settings, then reload the
// page to find the checkbox reverted (still persisted as checked) and the SMTP/Resend blocks
// hidden again - even though the SMTP/Resend data itself really did save. See todo.md's
// "Frontend component testing" section for the full trace.

const putSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            put: (url) => putSpy(url),
            post: vi.fn(),
            processing: false,
            errors: {},
        };
    },
    router: { post: vi.fn() },
}));

const BASE_SETTINGS = {
    use_instance_email_settings: true,
    smtp_from_name: '',
    smtp_from_address: '',
    smtp_enabled: false,
    smtp_host: '',
    smtp_port: '',
    smtp_encryption: 'starttls',
    smtp_username: '',
    smtp_password: '',
    smtp_timeout: '',
    resend_enabled: false,
    resend_api_key: '',
    deployment_success_email_notifications: false,
    deployment_failure_email_notifications: false,
    status_change_email_notifications: false,
    backup_success_email_notifications: false,
    backup_failure_email_notifications: false,
    scheduled_task_success_email_notifications: false,
    scheduled_task_failure_email_notifications: false,
    docker_cleanup_success_email_notifications: false,
    docker_cleanup_failure_email_notifications: false,
    server_disk_usage_email_notifications: false,
    server_reachable_email_notifications: false,
    server_unreachable_email_notifications: false,
    server_patch_email_notifications: false,
    traefik_outdated_email_notifications: false,
};

const BASE_PROPS = {
    settings: BASE_SETTINGS,
    isCloud: false,
    isInstanceAdmin: false,
    canSendTest: false,
    testEmailAddress: 'test@example.com',
    updateUrl: '/notifications/email',
    smtpUpdateUrl: '/notifications/email/smtp',
    resendUpdateUrl: '/notifications/email/resend',
    sendTestUrl: '/notifications/email/send-test',
    copyFromInstanceUrl: '/notifications/email/copy-from-instance',
};

describe('Notifications/Email', () => {
    beforeEach(() => {
        putSpy.mockClear();
    });

    it('saves immediately when "use system wide email settings" is toggled, not just local state', async () => {
        render(<Email {...BASE_PROPS} />);

        const toggle = screen.getByLabelText('Use system wide (transactional) email settings');
        toggle.click();

        expect(putSpy).toHaveBeenCalledWith('/notifications/email');
    });

    it('hides the SMTP/Resend blocks while "use system wide" is checked', () => {
        render(<Email {...BASE_PROPS} />);

        expect(screen.queryByLabelText('Host')).not.toBeInTheDocument();
    });

    it('reveals the SMTP/Resend blocks once "use system wide" is unchecked', () => {
        render(<Email {...BASE_PROPS} settings={{ ...BASE_SETTINGS, use_instance_email_settings: false }} />);

        expect(screen.getByText('SMTP Server')).toBeInTheDocument();
        expect(screen.getByText('Resend')).toBeInTheDocument();
    });

    it('does not auto-save on the isCloud variant beyond its own existing instant-save (no double-save)', () => {
        render(<Email {...BASE_PROPS} isCloud settings={{ ...BASE_SETTINGS, use_instance_email_settings: false }} />);

        const toggle = screen.getByLabelText('Use Hosted Email Service');
        toggle.click();

        expect(putSpy).toHaveBeenCalledTimes(1);
        expect(putSpy).toHaveBeenCalledWith('/notifications/email');
    });
});
