import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AppLayout from './AppLayout';

// Regression coverage for the other half of the toast-notification bug (see
// Components/Toast.test.jsx and todo.md's "Cleanup opportunities" section): Toast.jsx fixed
// window.toast() itself, but the bug only ever mattered in practice because AppLayout is what
// actually mounts <Toast /> for every real page and routes flash props into it. This file tests
// that wiring directly - a correct Toast.jsx mounted nowhere would have shipped the exact same
// silent failure. Heavier child components (GlobalSearchModal, LayoutPopups, ThemeSwitcher,
// WhatsNewButton) are mocked to keep this test focused on AppLayout's own logic - Toast itself is
// left real, since the mount + flash-wiring is exactly what's under test.

let pageProps = {};

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: pageProps }),
    Link: ({ href, className, children }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

vi.mock('../hooks/useAppearance', () => ({
    applyZoom: vi.fn(),
    pageWidthClass: () => '',
}));

vi.mock('../Components/GlobalSearchModal', () => ({ default: () => <div data-testid="global-search-modal" /> }));
vi.mock('../Components/LayoutPopups', () => ({ default: () => <div data-testid="layout-popups" /> }));
vi.mock('../Components/ThemeSwitcher', () => ({ default: () => <div data-testid="theme-switcher" /> }));
vi.mock('../Components/WhatsNewButton', () => ({ default: () => <div data-testid="whats-new-button" /> }));

function basePageProps(overrides = {}) {
    return {
        auth: { user: { name: 'Root User' } },
        currentTeam: { name: 'Root Team' },
        permissions: {},
        flash: {},
        changelog: null,
        ...overrides,
    };
}

describe('AppLayout', () => {
    it('mounts Toast unconditionally, so window.toast is a real function', () => {
        pageProps = basePageProps();
        render(<AppLayout>content</AppLayout>);

        expect(typeof window.toast).toBe('function');
    });

    it('renders a real toast from a flash message, mapping the flash type to a title and description', () => {
        pageProps = basePageProps({ flash: { success: 'Team updated.' } });
        render(<AppLayout>content</AppLayout>);

        expect(screen.getByText('Success')).toBeInTheDocument();
        expect(screen.getByText('Team updated.')).toBeInTheDocument();
    });

    it('maps a flash "error" key to the "danger" toast type, not a literal "error" type', () => {
        pageProps = basePageProps({ flash: { error: 'Something went wrong.' } });
        render(<AppLayout>content</AppLayout>);

        const toastText = screen.getByText('Error');
        expect(toastText).toHaveClass('text-red-500'); // Toast.jsx's TYPE_STYLES.danger
    });

    it('does not render a toast when there is no flash message', () => {
        pageProps = basePageProps({ flash: {} });
        const { container } = render(<AppLayout>content</AppLayout>);

        expect(container.querySelector('[role="alert"]')).not.toBeInTheDocument();
    });

    it('renders the Logout button as a real native form POST, not a client-side action', () => {
        pageProps = basePageProps();
        render(<AppLayout>content</AppLayout>);

        const logoutButton = screen.getByText('Logout');
        const form = logoutButton.closest('form');

        expect(form).toHaveAttribute('action', '/logout');
        expect(form).toHaveAttribute('method', 'POST');
    });

    it('hides permission-gated nav items when the permission is not granted', () => {
        pageProps = basePageProps({ permissions: { isInstanceAdmin: false, canAccessTerminal: false } });
        render(<AppLayout>content</AppLayout>);

        expect(screen.queryByText('Admin')).not.toBeInTheDocument();
        expect(screen.queryByText('Terminal')).not.toBeInTheDocument();
    });

    it('shows permission-gated nav items when the permission is granted', () => {
        pageProps = basePageProps({ permissions: { isInstanceAdmin: true, canAccessTerminal: true } });
        render(<AppLayout>content</AppLayout>);

        expect(screen.getByText('Admin')).toBeInTheDocument();
        expect(screen.getByText('Terminal')).toBeInTheDocument();
    });
});
