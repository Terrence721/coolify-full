import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SettingsEmail from './SettingsEmail';

// Real logic: setFromField syncs "From Name"/"From Address" across two independent useForm
// instances (smtp and resend) at once from a single pair of shared inputs - easy to get subtly
// wrong (updating only the visible form's own state, silently leaving the other form's copy
// stale until its own next edit). Also real: two fully independent submit forms sharing the same
// two fields, and a test-email modal that closes itself on a successful send. Previously untested.

const putSpy = vi.fn();
const postSpy = vi.fn();
let formErrors = { smtp: {}, resend: {} };

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        const bucket = 'smtp_enabled' in initial ? 'smtp' : 'resend_enabled' in initial ? 'resend' : 'test';
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            put: (url, options) => putSpy(bucket, url, data, options),
            post: (url, options) => {
                postSpy(bucket, url, data, options);
                options?.onSuccess?.();
            },
            processing: false,
            errors: formErrors[bucket] ?? {},
        };
    },
}));

function baseSettings(overrides = {}) {
    return {
        smtp_enabled: false,
        smtp_from_address: 'noreply@coolify.test',
        smtp_from_name: 'Coolify',
        smtp_host: 'smtp.mailgun.org',
        smtp_port: 587,
        smtp_encryption: 'starttls',
        smtp_username: 'smtp-user',
        smtp_password: 'smtp-pass',
        smtp_timeout: 30,
        resend_enabled: false,
        resend_api_key: '',
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        settings: baseSettings(overrides.settings),
        canSendTest: true,
        testEmailAddress: 'admin@coolify.test',
        smtpUpdateUrl: '/settings/email/smtp',
        resendUpdateUrl: '/settings/email/resend',
        sendTestUrl: '/settings/email/send-test',
        ...overrides,
    };
}

beforeEach(() => {
    formErrors = { smtp: {}, resend: {} };
});

afterEach(() => {
    putSpy.mockClear();
    postSpy.mockClear();
    vi.restoreAllMocks();
});

describe('SettingsEmail - shared From Name/Address sync', () => {
    it('renders the shared From Name/Address fields pre-filled from settings', () => {
        render(<SettingsEmail {...baseProps()} />);

        expect(screen.getByLabelText('From Name')).toHaveValue('Coolify');
        expect(screen.getByLabelText('From Address')).toHaveValue('noreply@coolify.test');
    });

    it('propagates a From Name edit into the resend form, not just the visible smtp form', () => {
        render(<SettingsEmail {...baseProps()} />);

        fireEvent.change(screen.getByLabelText('From Name'), { target: { value: 'New Name' } });

        // Two Save buttons exist (SMTP form, Resend form); submit the Resend one specifically.
        fireEvent.click(screen.getAllByRole('button', { name: 'Save' })[1]);

        const resendCall = putSpy.mock.calls.find(([bucket]) => bucket === 'resend');
        expect(resendCall[2]).toMatchObject({ smtp_from_name: 'New Name' });
    });

    it('propagates a From Address edit into both forms', () => {
        render(<SettingsEmail {...baseProps()} />);

        fireEvent.change(screen.getByLabelText('From Address'), { target: { value: 'new@coolify.test' } });

        fireEvent.click(screen.getAllByRole('button', { name: 'Save' })[0]);
        fireEvent.click(screen.getAllByRole('button', { name: 'Save' })[1]);

        const smtpCall = putSpy.mock.calls.find(([bucket]) => bucket === 'smtp');
        const resendCall = putSpy.mock.calls.find(([bucket]) => bucket === 'resend');
        expect(smtpCall[2]).toMatchObject({ smtp_from_address: 'new@coolify.test' });
        expect(resendCall[2]).toMatchObject({ smtp_from_address: 'new@coolify.test' });
    });
});

describe('SettingsEmail - independent submit forms', () => {
    it('submits only the SMTP form to smtpUpdateUrl when its own Save is clicked', () => {
        render(<SettingsEmail {...baseProps()} />);

        fireEvent.click(screen.getAllByRole('button', { name: 'Save' })[0]);

        expect(putSpy).toHaveBeenCalledTimes(1);
        expect(putSpy.mock.calls[0][0]).toBe('smtp');
        expect(putSpy.mock.calls[0][1]).toBe('/settings/email/smtp');
    });

    it('submits only the Resend form to resendUpdateUrl when its own Save is clicked', () => {
        render(<SettingsEmail {...baseProps()} />);

        fireEvent.click(screen.getAllByRole('button', { name: 'Save' })[1]);

        expect(putSpy).toHaveBeenCalledTimes(1);
        expect(putSpy.mock.calls[0][0]).toBe('resend');
        expect(putSpy.mock.calls[0][1]).toBe('/settings/email/resend');
    });

    it('renders SMTP and Resend field-level errors independently', () => {
        formErrors = {
            smtp: { smtp_host: 'SMTP Host is required.' },
            resend: { resend_api_key: 'Resend API Key is required.' },
        };
        render(<SettingsEmail {...baseProps()} />);

        expect(screen.getByText('SMTP Host is required.')).toBeInTheDocument();
        expect(screen.getByText('Resend API Key is required.')).toBeInTheDocument();
    });
});

describe('SettingsEmail - test email modal', () => {
    it('hides the Send Test Email button when canSendTest is false', () => {
        render(<SettingsEmail {...baseProps({ canSendTest: false })} />);
        expect(screen.queryByRole('button', { name: 'Send Test Email' })).not.toBeInTheDocument();
    });

    it('opens the test modal pre-filled with testEmailAddress, and closes it after a successful send', () => {
        render(<SettingsEmail {...baseProps()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Send Test Email' }));
        expect(screen.getByPlaceholderText('test@example.com')).toHaveValue('admin@coolify.test');

        fireEvent.click(screen.getByRole('button', { name: 'Send Email' }));

        expect(postSpy).toHaveBeenCalledWith('test', '/settings/email/send-test', expect.anything(), expect.anything());
        expect(screen.queryByPlaceholderText('test@example.com')).not.toBeInTheDocument();
    });
});
