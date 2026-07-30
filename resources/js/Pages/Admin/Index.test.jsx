import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Index from './Index';

// The /admin page (root only), live-verified end-to-end during the 2026-07-21 smoke test
// (issue #25): searching for a throwaway user by partial email, clicking their result card to
// impersonate, confirming the "Who am I now?" line updated and a "Go back to root" button
// appeared, then switching back. This suite locks that in as automated coverage; the page was
// previously entirely untested. **Found and fixed a real, severe bug along the way** (issue
// #37, unrelated to this page's own JS): the very first impersonation attempt hit a real 500,
// since fresh users (root/invited/OAuth) never had `email_verified_at` set and non-cloud mode
// skipped the app's own unverified-email redirect, falling through to Laravel's stock
// `verified` middleware — fixed on the backend, not something this frontend suite re-tests.

const getSpy = vi.fn();
const postSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        get: (url, data, options) => getSpy(url, data, options),
        post: (url, data) => postSpy(url, data),
    },
}));

function baseProps(overrides = {}) {
    return {
        name: 'Root User',
        email: 'root@example.com',
        impersonating: false,
        search: null,
        foundUsers: [],
        backUrl: '/admin/impersonate/back',
        switchUserUrl: '/admin/impersonate',
        ...overrides,
    };
}

describe('Admin/Index', () => {
    beforeEach(() => {
        getSpy.mockClear();
        postSpy.mockClear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows the current name and email', () => {
        render(<Index {...baseProps({ name: 'Root User', email: 'root@example.com' })} />);
        expect(screen.getByText('Root User (root@example.com)')).toBeInTheDocument();
    });

    it('hides "Go back to root" when not impersonating', () => {
        render(<Index {...baseProps({ impersonating: false })} />);
        expect(screen.queryByRole('button', { name: 'Go back to root' })).not.toBeInTheDocument();
    });

    it('shows "Go back to root" and posts to backUrl when impersonating', () => {
        render(<Index {...baseProps({ impersonating: true, backUrl: '/admin/impersonate/back' })} />);
        act(() => screen.getByRole('button', { name: 'Go back to root' }).click());
        expect(postSpy).toHaveBeenCalledWith('/admin/impersonate/back', undefined);
    });

    it('updates the search field as the user types', () => {
        render(<Index {...baseProps()} />);
        const input = screen.getByPlaceholderText('Search for a user');
        const inputSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        act(() => {
            inputSetter.call(input, 'jane');
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
        expect(input).toHaveValue('jane');
    });

    it('submits the search via router.get("/admin", { search }, { preserveState: true })', () => {
        render(<Index {...baseProps()} />);
        const input = screen.getByPlaceholderText('Search for a user');
        const inputSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        act(() => {
            inputSetter.call(input, 'jane');
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });

        act(() => screen.getByRole('button', { name: 'Search' }).click());

        expect(getSpy).toHaveBeenCalledWith('/admin', { search: 'jane' }, { preserveState: true });
    });

    it('shows no results section at all when there is no active search', () => {
        render(<Index {...baseProps({ search: null, foundUsers: [] })} />);
        expect(screen.queryByText(/No users found/)).not.toBeInTheDocument();
    });

    it('renders each found user as a clickable card', () => {
        render(
            <Index
                {...baseProps({
                    search: 'jane',
                    foundUsers: [
                        { id: 1, name: 'Jane Doe', email: 'jane@example.com' },
                        { id: 2, name: 'Jane Smith', email: 'jane.smith@example.com' },
                    ],
                })}
            />,
        );
        expect(screen.getByText('Jane Doe')).toBeInTheDocument();
        expect(screen.getByText('jane@example.com')).toBeInTheDocument();
        expect(screen.getByText('Jane Smith')).toBeInTheDocument();
    });

    it('shows a "no users found" message naming the search term when nothing matches', () => {
        render(<Index {...baseProps({ search: 'nobody', foundUsers: [] })} />);
        expect(screen.getByText('No users found with nobody')).toBeInTheDocument();
    });

    it('clicking a found user posts { user_id } to switchUserUrl', () => {
        render(
            <Index
                {...baseProps({
                    search: 'jane',
                    switchUserUrl: '/admin/impersonate',
                    foundUsers: [{ id: 42, name: 'Jane Doe', email: 'jane@example.com' }],
                })}
            />,
        );
        act(() => screen.getByText('Jane Doe').closest('.coolbox').click());
        expect(postSpy).toHaveBeenCalledWith('/admin/impersonate', { user_id: 42 });
    });
});
