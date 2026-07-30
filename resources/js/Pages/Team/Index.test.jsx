import { render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Index from './Index';

// The /team page, live-verified end-to-end during the 2026-07-21 smoke test (issue #25) —
// renamed the team, saved, reloaded to confirm it persisted, then reverted; the Danger Zone
// correctly showed "This is the default team. You can't delete it." with no Delete button for
// this dev team (id=0). Also found and fixed real dev-data drift the same day (a stray
// character in the team name from earlier testing), not an app bug. This suite locks in the
// previously-untested logic: the name/description form, the isInstanceAdmin-gated Admin View
// link, and all 4 Danger Zone states (default-team, last-team, not-empty with its blocking
// resource lists, and the real deletable state with its typed-name confirmation).

const putSpy = vi.fn();
const deleteSpy = vi.fn();
let mockPermissions = {};

vi.mock('@inertiajs/react', () => ({
    router: { delete: (url) => deleteSpy(url) },
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            put: (url) => putSpy(url),
            processing: false,
            errors: {},
        };
    },
    usePage: () => ({ props: { permissions: mockPermissions } }),
}));

function baseProps(overrides = {}) {
    return {
        team: { name: 'Root Team', description: 'The default team' },
        canUpdate: true,
        canDelete: true,
        deletionBlockedReason: 'default-team',
        blockingResources: {},
        updateUrl: '/team',
        deleteUrl: '/team',
        ...overrides,
    };
}

describe('Team/Index', () => {
    beforeEach(() => {
        putSpy.mockClear();
        deleteSpy.mockClear();
        mockPermissions = {};
        vi.spyOn(window, 'prompt');
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it("renders the team's current name and description in the form", () => {
        render(<Index {...baseProps({ team: { name: 'Root Team', description: 'The default team' } })} />);
        expect(screen.getByLabelText('Name')).toHaveValue('Root Team');
        expect(screen.getByLabelText('Description')).toHaveValue('The default team');
    });

    it('falls back to an empty string when description is null', () => {
        render(<Index {...baseProps({ team: { name: 'Root Team', description: null } })} />);
        expect(screen.getByLabelText('Description')).toHaveValue('');
    });

    it('submits the edited name/description via put(updateUrl)', () => {
        render(<Index {...baseProps({ updateUrl: '/team' })} />);
        act(() => screen.getByRole('button', { name: 'Save' }).click());
        expect(putSpy).toHaveBeenCalledWith('/team');
    });

    it('disables the form fields and hides Save when canUpdate is false', () => {
        render(<Index {...baseProps({ canUpdate: false })} />);
        expect(screen.getByLabelText('Name')).toBeDisabled();
        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
    });

    it('only shows the Admin View link for an instance admin', () => {
        mockPermissions = { isInstanceAdmin: false };
        const { unmount } = render(<Index {...baseProps()} />);
        expect(screen.queryByRole('link', { name: 'Admin View' })).not.toBeInTheDocument();
        unmount();

        mockPermissions = { isInstanceAdmin: true };
        render(<Index {...baseProps()} />);
        expect(screen.getByRole('link', { name: 'Admin View' })).toHaveAttribute('href', '/team/admin');
    });

    it('hides the entire Danger Zone when canDelete is false', () => {
        render(<Index {...baseProps({ canDelete: false })} />);
        expect(screen.queryByText('Danger Zone')).not.toBeInTheDocument();
    });

    it('shows the default-team blocking message with no Delete button', () => {
        render(<Index {...baseProps({ deletionBlockedReason: 'default-team' })} />);
        expect(screen.getByText("This is the default team. You can't delete it.")).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument();
    });

    it('shows the last-team blocking message with no Delete button', () => {
        render(<Index {...baseProps({ deletionBlockedReason: 'last-team' })} />);
        expect(screen.getByText("You can't delete your last / personal team.")).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument();
    });

    it('lists only the non-empty blocking resource categories when not-empty', () => {
        render(
            <Index
                {...baseProps({
                    deletionBlockedReason: 'not-empty',
                    blockingResources: { projects: ['E-Commerce'], servers: [], privateKeys: [], sources: ['github-key'] },
                })}
            />,
        );
        expect(screen.getByText('E-Commerce')).toBeInTheDocument();
        expect(screen.getByText('github-key')).toBeInTheDocument();
        expect(screen.queryByText('Servers:')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument();
    });

    it('shows the real Delete button, gated by a typed-name confirmation, when nothing blocks deletion', () => {
        window.prompt.mockReturnValue('Wrong Name');
        render(<Index {...baseProps({ team: { name: 'Root Team', description: '' }, deletionBlockedReason: null, deleteUrl: '/team' })} />);

        expect(screen.getByRole('button', { name: 'Delete' })).toBeInTheDocument();

        act(() => screen.getByRole('button', { name: 'Delete' }).click());
        expect(deleteSpy).not.toHaveBeenCalled();

        window.prompt.mockReturnValue('Root Team');
        act(() => screen.getByRole('button', { name: 'Delete' }).click());
        expect(deleteSpy).toHaveBeenCalledWith('/team');
    });
});
