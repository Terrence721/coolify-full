import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
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
let pageUrl = '/';
const routerPost = vi.fn();

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: pageProps, url: pageUrl }),
    Link: ({ href, className, children }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
    router: { post: (...args) => routerPost(...args) },
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
        currentTeam: { id: 1, name: 'Root Team' },
        availableTeams: [{ id: 1, name: 'Root Team' }],
        permissions: {},
        flash: {},
        changelog: null,
        ...overrides,
    };
}

describe('AppLayout', () => {
    beforeEach(() => {
        routerPost.mockClear();
    });

    // Regression coverage for issue #69: a read-only team name was shown instead of a working
    // switcher, with no in-app way to move between teams for a user in more than one.
    describe('team switcher', () => {
        it('shows a plain read-only team name when the user belongs to only one team', () => {
            pageProps = basePageProps();
            render(<AppLayout>content</AppLayout>);

            expect(screen.getByText('Root Team')).toBeInTheDocument();
            expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
        });

        it('shows a working dropdown listing every team when the user belongs to more than one', () => {
            pageProps = basePageProps({
                currentTeam: { id: 1, name: 'Root Team' },
                availableTeams: [
                    { id: 1, name: 'Root Team' },
                    { id: 2, name: 'Second Team' },
                ],
            });
            render(<AppLayout>content</AppLayout>);

            const select = screen.getByRole('combobox', { name: /switch team/i });
            expect(select).toHaveValue('1');
            expect(screen.getByRole('option', { name: 'Root Team' })).toBeInTheDocument();
            expect(screen.getByRole('option', { name: 'Second Team' })).toBeInTheDocument();
        });

        it('posts to /team/switch with the selected team_id when a different team is chosen', () => {
            pageProps = basePageProps({
                currentTeam: { id: 1, name: 'Root Team' },
                availableTeams: [
                    { id: 1, name: 'Root Team' },
                    { id: 2, name: 'Second Team' },
                ],
            });
            render(<AppLayout>content</AppLayout>);

            const select = screen.getByRole('combobox', { name: /switch team/i });
            fireEvent.change(select, { target: { value: '2' } });

            expect(routerPost).toHaveBeenCalledWith('/team/switch', { team_id: '2' });
        });

        it('does not post when the currently-selected team is chosen again', () => {
            pageProps = basePageProps({
                currentTeam: { id: 1, name: 'Root Team' },
                availableTeams: [
                    { id: 1, name: 'Root Team' },
                    { id: 2, name: 'Second Team' },
                ],
            });
            render(<AppLayout>content</AppLayout>);

            const select = screen.getByRole('combobox', { name: /switch team/i });
            fireEvent.change(select, { target: { value: '1' } });

            expect(routerPost).not.toHaveBeenCalled();
        });
    });

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

    // Regression coverage for issue #126: zero error boundaries anywhere meant a page component
    // throwing during render blanked the *entire* page, nav/sidebar chrome included, with no
    // recovery until a manual reload.
    describe('page content error boundary', () => {
        function Bomb() {
            throw new Error('boom');
        }

        beforeEach(() => {
            vi.spyOn(console, 'error').mockImplementation(() => {});
            pageUrl = '/';
        });

        afterEach(() => {
            vi.restoreAllMocks();
        });

        it('shows a fallback in the content area instead of the whole page going blank when a page component throws', () => {
            pageProps = basePageProps();
            render(
                <AppLayout>
                    <Bomb />
                </AppLayout>,
            );

            expect(screen.getByText('Something went wrong loading this page.')).toBeInTheDocument();
        });

        it('keeps the sidebar and its nav links usable when the page content throws', () => {
            pageProps = basePageProps();
            render(
                <AppLayout>
                    <Bomb />
                </AppLayout>,
            );

            expect(screen.getByText('Dashboard')).toBeInTheDocument();
            expect(screen.getByText('Projects')).toBeInTheDocument();
            expect(screen.getByText('Logout')).toBeInTheDocument();
        });

        it('resets the boundary on navigation instead of staying stuck on the previous page error', () => {
            pageProps = basePageProps();
            pageUrl = '/broken-page';
            const { rerender } = render(
                <AppLayout>
                    <Bomb />
                </AppLayout>,
            );
            expect(screen.getByText('Something went wrong loading this page.')).toBeInTheDocument();

            pageUrl = '/healthy-page';
            rerender(<AppLayout>healthy content</AppLayout>);

            expect(screen.getByText('healthy content')).toBeInTheDocument();
            expect(screen.queryByText('Something went wrong loading this page.')).not.toBeInTheDocument();
        });
    });
});
