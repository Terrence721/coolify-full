import { render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AdminView from './AdminView';

// The /team/admin page, live-verified end-to-end during the 2026-07-21 smoke test (issue #25) —
// searched for a throwaway user by partial email match, deleted via the two-step confirmation
// (typed the target's name, then the logged-in root user's own password), confirmed the row and
// success message, and confirmed both the user and their auto-created personal team were fully
// gone afterward, no orphaned team left behind. This suite locks the frontend logic in as
// automated coverage; the page was previously entirely untested.

const getSpy = vi.fn();
const deleteSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: { delete: (url, options) => deleteSpy(url, options) },
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            get: (url, options) => getSpy(url, options),
            processing: false,
        };
    },
}));

function baseUser(overrides = {}) {
    return { id: 1, name: 'Jane Doe', email: 'jane@example.com', ...overrides };
}

function baseProps(overrides = {}) {
    return {
        search: '',
        users: [],
        lotsOfUsers: false,
        deleteUserUrl: '/team/admin/delete-user',
        ...overrides,
    };
}

describe('Team/AdminView', () => {
    beforeEach(() => {
        getSpy.mockClear();
        deleteSpy.mockClear();
        vi.spyOn(window, 'prompt');
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows the empty state when there are no users other than root', () => {
        render(<AdminView {...baseProps({ users: [] })} />);
        expect(screen.getByText('No users found other than the root.')).toBeInTheDocument();
    });

    it("renders each user's name and email", () => {
        render(<AdminView {...baseProps({ users: [baseUser()] })} />);
        expect(screen.getByText('Jane Doe')).toBeInTheDocument();
        expect(screen.getByText('jane@example.com')).toBeInTheDocument();
    });

    it('shows the "more users" hint only when lotsOfUsers is true', () => {
        const { unmount } = render(<AdminView {...baseProps({ lotsOfUsers: false })} />);
        expect(screen.queryByText(/There are more users/)).not.toBeInTheDocument();
        unmount();

        render(<AdminView {...baseProps({ lotsOfUsers: true })} />);
        expect(screen.getByText(/There are more users/)).toBeInTheDocument();
    });

    it('updates the search field and submits via get("/team/admin", { preserveState: true })', () => {
        render(<AdminView {...baseProps({ search: '' })} />);
        const input = screen.getByPlaceholderText('Search for a user');
        const inputSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        act(() => {
            inputSetter.call(input, 'jane');
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
        expect(input).toHaveValue('jane');

        act(() => screen.getByRole('button', { name: 'Search' }).click());

        expect(getSpy).toHaveBeenCalledWith('/team/admin', { preserveState: true });
    });

    it('does nothing if the typed name does not match', () => {
        window.prompt.mockReturnValueOnce('Wrong Name');
        render(<AdminView {...baseProps({ users: [baseUser()] })} />);

        act(() => screen.getByRole('button', { name: 'Delete' }).click());

        expect(deleteSpy).not.toHaveBeenCalled();
    });

    it('does nothing if the password prompt is cancelled after a correct name', () => {
        window.prompt.mockReturnValueOnce('Jane Doe').mockReturnValueOnce(null);
        render(<AdminView {...baseProps({ users: [baseUser()] })} />);

        act(() => screen.getByRole('button', { name: 'Delete' }).click());

        expect(deleteSpy).not.toHaveBeenCalled();
    });

    it('deletes via router.delete(deleteUserUrl, { data: { id, password } }) once both prompts match', () => {
        window.prompt.mockReturnValueOnce('Jane Doe').mockReturnValueOnce('root-password');
        render(<AdminView {...baseProps({ users: [baseUser({ id: 42 })], deleteUserUrl: '/team/admin/delete-user' })} />);

        act(() => screen.getByRole('button', { name: 'Delete' }).click());

        expect(deleteSpy).toHaveBeenCalledWith('/team/admin/delete-user', { data: { id: 42, password: 'root-password' } });
    });
});
