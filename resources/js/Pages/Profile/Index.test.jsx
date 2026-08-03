import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import Index from './Index';

// The account settings page: profile name update, an email-change flow gated behind a
// verification code, a password change that resets its own form on success, and a 2FA
// state machine (setup QR / just-confirmed recovery codes / steady-state enable-disable).
//
// Found and fixed a real bug while writing this suite: the "Regenerate Recovery Codes" flow
// flashes twoFactor.status = 'recovery-codes-generated' back from the server (Fortify's
// Fortify::RECOVERY_CODES_GENERATED constant), but the JSX nested that status check *inside*
// the `{!twoFactor.status && (...)}` branch - a status of 'recovery-codes-generated' is truthy,
// so it can never be true there. Worse, since 'recovery-codes-generated' doesn't match either of
// the other two top-level status branches ('two-factor-authentication-enabled' /
// '-confirmed') either, the entire Two-Factor section (including the Disable/Regenerate buttons)
// silently disappeared right after regenerating codes, until the next full page load.

const putSpies = { profile: vi.fn(), password: vi.fn() };
const postSpies = { emailChange: vi.fn(), verify: vi.fn() };
const resetSpies = { password: vi.fn() };
let formErrors = { profile: {}, emailChange: {}, verify: {}, password: {} };

function bucketFor(initial) {
    if ('name' in initial) return 'profile';
    if ('new_email' in initial) return 'emailChange';
    if ('email_verification_code' in initial) return 'verify';
    return 'password';
}

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        const bucket = bucketFor(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            put: (url, options) => {
                putSpies[bucket]?.(url, data, options);
                options?.onSuccess?.();
            },
            post: (url, options) => {
                postSpies[bucket]?.(url, data, options);
                options?.onSuccess?.();
            },
            reset: () => resetSpies[bucket]?.(),
            processing: false,
            errors: formErrors[bucket] ?? {},
        };
    },
    router: {
        post: (url) => routerPostSpy(url),
    },
}));

const routerPostSpy = vi.fn();

function profileForm() {
    return screen.getByText('General', { selector: 'h2' }).closest('form');
}

function passwordForm() {
    return screen.getByText('Change Password').closest('form');
}

function baseProps(overrides = {}) {
    return {
        name: 'Terrence Daniels',
        email: 'terrence@example.com',
        pendingEmail: null,
        showVerification: false,
        verificationExpiryMinutes: 10,
        twoFactor: { confirmed: false, status: null, recoveryCodes: null },
        updateUrl: '/profile',
        requestEmailChangeUrl: '/profile/email/request',
        verifyEmailChangeUrl: '/profile/email/verify',
        resendCodeUrl: '/profile/email/resend',
        cancelEmailChangeUrl: '/profile/email/cancel',
        updatePasswordUrl: '/profile/password',
        ...overrides,
    };
}

afterEach(() => {
    putSpies.profile.mockClear();
    putSpies.password.mockClear();
    postSpies.emailChange.mockClear();
    postSpies.verify.mockClear();
    resetSpies.password.mockClear();
    routerPostSpy.mockClear();
    formErrors = { profile: {}, emailChange: {}, verify: {}, password: {} };
});

it('prefills the name/email fields and submits the profile form', () => {
    render(<Index {...baseProps()} />);

    expect(screen.getByLabelText('Name')).toHaveValue('Terrence Daniels');
    expect(screen.getByLabelText('Email')).toHaveValue('terrence@example.com');
    expect(screen.getByLabelText('Email')).toHaveAttribute('readonly');

    fireEvent.click(within(profileForm()).getByRole('button', { name: 'Save' }));

    expect(putSpies.profile).toHaveBeenCalledWith('/profile', { name: 'Terrence Daniels' }, undefined);
});

it('submits an email-change request while not yet verifying', () => {
    render(<Index {...baseProps()} />);

    fireEvent.change(screen.getByLabelText('New Email Address'), { target: { value: 'new@example.com' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send Verification Code' }));

    expect(postSpies.emailChange).toHaveBeenCalledWith('/profile/email/request', { new_email: 'new@example.com' }, undefined);
    expect(screen.queryByLabelText('Verification Code (6 digits)')).not.toBeInTheDocument();
});

it('shows the verification-code form instead once a change is pending', () => {
    render(<Index {...baseProps({ showVerification: true, pendingEmail: 'new@example.com' })} />);

    expect(screen.queryByLabelText('New Email Address')).not.toBeInTheDocument();
    expect(screen.getByLabelText('Verification Code (6 digits)')).toBeInTheDocument();
    expect(screen.getByText(/Verification code sent to new@example.com/)).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Verification Code (6 digits)'), { target: { value: '123456' } });
    fireEvent.click(screen.getByRole('button', { name: 'Verify & Update Email' }));

    expect(postSpies.verify).toHaveBeenCalledWith('/profile/email/verify', { email_verification_code: '123456' }, undefined);
});

it('resends and cancels the pending email change via router.post', () => {
    render(<Index {...baseProps({ showVerification: true, pendingEmail: 'new@example.com' })} />);

    fireEvent.click(screen.getByRole('button', { name: 'Resend Code' }));
    expect(routerPostSpy).toHaveBeenCalledWith('/profile/email/resend');

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(routerPostSpy).toHaveBeenCalledWith('/profile/email/cancel');
});

it('submits the password change and resets the form on success', () => {
    render(<Index {...baseProps()} />);

    fireEvent.change(screen.getByLabelText('Current Password'), { target: { value: 'old-pass' } });
    fireEvent.change(screen.getByLabelText('New Password'), { target: { value: 'new-pass' } });
    fireEvent.change(screen.getByLabelText('New Password Again'), { target: { value: 'new-pass' } });
    fireEvent.click(within(passwordForm()).getByRole('button', { name: 'Save' }));

    expect(putSpies.password).toHaveBeenCalledWith(
        '/profile/password',
        { current_password: 'old-pass', new_password: 'new-pass', new_password_confirmation: 'new-pass' },
        expect.objectContaining({ onSuccess: expect.any(Function) }),
    );
    expect(resetSpies.password).toHaveBeenCalled();
});

it('renders the QR setup flow while status is two-factor-authentication-enabled', () => {
    render(
        <Index
            {...baseProps({
                twoFactor: {
                    confirmed: false,
                    status: 'two-factor-authentication-enabled',
                    qrCodeSvg: '<svg>qr</svg>',
                    qrCodeUrl: 'otpauth://totp/test',
                    secret: 'SECRET123',
                },
            })}
        />,
    );

    expect(screen.getByText('SECRET123')).toBeInTheDocument();
    expect(screen.getByText('otpauth://totp/test')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Validate 2FA' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Configure' })).not.toBeInTheDocument();
});

it('renders the just-confirmed recovery codes once status is two-factor-authentication-confirmed', () => {
    render(
        <Index
            {...baseProps({
                twoFactor: {
                    confirmed: true,
                    status: 'two-factor-authentication-confirmed',
                    recoveryCodes: ['code-one', 'code-two'],
                },
            })}
        />,
    );

    expect(screen.getByText('Two factor authentication confirmed and enabled successfully.')).toBeInTheDocument();
    expect(screen.getByText('code-one')).toBeInTheDocument();
    expect(screen.getByText('code-two')).toBeInTheDocument();
});

it('shows a plain Configure button when 2FA has never been set up', () => {
    render(<Index {...baseProps({ twoFactor: { confirmed: false, status: null, recoveryCodes: null } })} />);

    expect(screen.getByRole('button', { name: 'Configure' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Disable' })).not.toBeInTheDocument();
});

it('shows Disable/Regenerate once 2FA is confirmed with no pending session status', () => {
    render(<Index {...baseProps({ twoFactor: { confirmed: true, status: null, recoveryCodes: null } })} />);

    expect(screen.getByText('enabled')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Disable' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Regenerate Recovery Codes' })).toBeInTheDocument();
});

// Regression test for the real bug described at the top of this file.
it('still shows Disable/Regenerate and the fresh codes after Regenerate Recovery Codes', () => {
    render(
        <Index
            {...baseProps({
                twoFactor: {
                    confirmed: true,
                    status: 'recovery-codes-generated',
                    recoveryCodes: ['fresh-code-one', 'fresh-code-two'],
                },
            })}
        />,
    );

    expect(screen.getByRole('button', { name: 'Disable' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Regenerate Recovery Codes' })).toBeInTheDocument();
    expect(screen.getByText('fresh-code-one')).toBeInTheDocument();
    expect(screen.getByText('fresh-code-two')).toBeInTheDocument();
});
