import { render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ForcePasswordReset from './ForcePasswordReset';

// Covers the bare force-password-reset page: readonly email, password/confirmation inputs wired
// to useForm, error display, processing-disabled submit button, and the put() call on submit -
// none of it previously tested. Also covers ForcePasswordReset.layout, the opt-out of the default
// AppLayout wrapper that makes this page render without a navbar/sidebar (the exact behavior
// manually verified end-to-end in the 2026-07-22 force-password-reset smoke test).

const putSpy = vi.fn();
let mockProcessing = false;
let mockErrors = {};

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            put: (url, options) => putSpy(url, options),
            processing: mockProcessing,
            errors: mockErrors,
        };
    },
}));

beforeEach(() => {
    putSpy.mockClear();
    mockProcessing = false;
    mockErrors = {};
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('ForcePasswordReset', () => {
    it('shows the email as a readonly field', () => {
        render(<ForcePasswordReset email="reset-me@example.com" updateUrl="/force-password-reset" />);

        const emailInput = screen.getByLabelText('Email');
        expect(emailInput).toHaveValue('reset-me@example.com');
        expect(emailInput).toHaveAttribute('readonly');
    });

    it('updates password and confirmation fields as the user types', () => {
        render(<ForcePasswordReset email="reset-me@example.com" updateUrl="/force-password-reset" />);

        act(() => screen.getByLabelText('New Password').focus());
        const passwordSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        act(() => {
            passwordSetter.call(screen.getByLabelText('New Password'), 'newpassword456');
            screen.getByLabelText('New Password').dispatchEvent(new Event('input', { bubbles: true }));
        });
        expect(screen.getByLabelText('New Password')).toHaveValue('newpassword456');

        act(() => {
            passwordSetter.call(screen.getByLabelText('Confirm New Password'), 'newpassword456');
            screen.getByLabelText('Confirm New Password').dispatchEvent(new Event('input', { bubbles: true }));
        });
        expect(screen.getByLabelText('Confirm New Password')).toHaveValue('newpassword456');
    });

    it('shows a validation error under the password field when present', () => {
        mockErrors = { password: 'The password field is required.' };
        render(<ForcePasswordReset email="reset-me@example.com" updateUrl="/force-password-reset" />);

        expect(screen.getByText('The password field is required.')).toBeInTheDocument();
    });

    it('disables the submit button while processing', () => {
        mockProcessing = true;
        render(<ForcePasswordReset email="reset-me@example.com" updateUrl="/force-password-reset" />);

        expect(screen.getByRole('button', { name: 'Reset Password' })).toBeDisabled();
    });

    it('submits via put to updateUrl', () => {
        render(<ForcePasswordReset email="reset-me@example.com" updateUrl="/force-password-reset" />);

        const form = screen.getByRole('button', { name: 'Reset Password' }).closest('form');
        act(() => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));

        expect(putSpy).toHaveBeenCalledWith('/force-password-reset', undefined);
    });

    it('opts out of the default AppLayout wrapper, rendering as a bare page', () => {
        const page = { key: 'unwrapped-page-marker' };
        expect(ForcePasswordReset.layout(page)).toBe(page);
    });
});
