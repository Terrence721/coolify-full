import { act } from 'react';
import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import VerifyEmail from './VerifyEmail';

// The bare email-verification gate page - the last remaining consumer of the pre-migration
// layout, opted out of AppLayout the same way ForcePasswordReset.jsx is. Real logic: a `sending`
// state disabling the resend button for the duration of the router.post call (re-enabled via the
// request's own onFinish callback, not a fixed timeout), and flash.success/flash.error rendered
// directly from usePage() props - this page bypasses AppLayout entirely, so it can't rely on
// AppLayout's own flash-to-Toast wiring the way every other page in the app does.

const postSpy = vi.fn();
let mockFlash = {};

vi.mock('@inertiajs/react', () => ({
    router: { post: (url, data, options) => postSpy(url, data, options) },
    usePage: () => ({ props: { flash: mockFlash } }),
}));

beforeEach(() => {
    postSpy.mockClear();
    mockFlash = {};
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('VerifyEmail', () => {
    it('renders the verification message', () => {
        render(<VerifyEmail resendUrl="/email/verification-notification" />);
        expect(screen.getByText('Verification Email Sent')).toBeInTheDocument();
    });

    it('posts to resendUrl with preserveScroll when the resend button is clicked', () => {
        render(<VerifyEmail resendUrl="/email/verification-notification" />);

        screen.getByRole('button', { name: 'Send Verification Email Again' }).click();

        expect(postSpy).toHaveBeenCalledWith(
            '/email/verification-notification',
            {},
            expect.objectContaining({ preserveScroll: true, onFinish: expect.any(Function) }),
        );
    });

    it('disables the resend button while the request is in flight, then re-enables it once onFinish fires', () => {
        render(<VerifyEmail resendUrl="/email/verification-notification" />);

        const button = screen.getByRole('button', { name: 'Send Verification Email Again' });
        act(() => button.click());
        expect(button).toBeDisabled();

        act(() => postSpy.mock.calls[0][2].onFinish());
        expect(button).toBeEnabled();
    });

    it('shows a success flash message when present', () => {
        mockFlash = { success: 'Verification email sent!' };
        render(<VerifyEmail resendUrl="/email/verification-notification" />);
        expect(screen.getByText('Verification email sent!')).toBeInTheDocument();
    });

    it('shows an error flash message when present', () => {
        mockFlash = { error: 'Something went wrong.' };
        render(<VerifyEmail resendUrl="/email/verification-notification" />);
        expect(screen.getByText('Something went wrong.')).toBeInTheDocument();
    });

    it('shows neither flash message when flash is empty', () => {
        render(<VerifyEmail resendUrl="/email/verification-notification" />);
        expect(screen.queryByText(/sent!|wrong\./)).not.toBeInTheDocument();
    });

    it('opts out of the default AppLayout wrapper, rendering as a bare page', () => {
        const page = { key: 'unwrapped-page-marker' };
        expect(VerifyEmail.layout(page)).toBe(page);
    });
});
