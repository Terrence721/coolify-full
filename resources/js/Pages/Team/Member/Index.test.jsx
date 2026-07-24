import { render, screen, within } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Index from './Index';

// Regression coverage for a real bug found via live network trace during manual smoke testing
// (issue #25, /team/members): submitInvite() used to call useForm's post(url, { data: {...},
// ... }), but useForm().post(url, options) has no `data` override in options - that's only
// supported by router.post(url, data, options). The `via` field was silently dropped from every
// request, failing the backend's required `via` validation with zero visible feedback (errors.via
// is never rendered anywhere in this component). Also covers role changes, member removal,
// invitation revocation, and the copy-link button - none of which had any test coverage before.

// React 19 patches the native <input> value setter to track controlled-component state -
// directly assigning `.value` then dispatching a bare event doesn't notify it. Using the real
// native setter first (bypassing React's patched one) is the standard workaround absent
// @testing-library/user-event, which isn't installed in this project.
function typeInto(element, value) {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

const postSpy = vi.fn();
const putSpy = vi.fn();
const deleteSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => postSpy(url, data, options),
        put: (url, data, options) => putSpy(url, data, options),
        delete: (url, options) => deleteSpy(url, options),
    },
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            post: () => {
                throw new Error('useForm().post() does not support a data override - use router.post() instead');
            },
            processing: false,
            errors: {},
            reset: vi.fn(),
        };
    },
    usePage: () => ({ props: { permissions: {} } }),
}));

function baseProps(overrides = {}) {
    return {
        members: [
            { id: 1, name: 'Current User', email: 'me@example.com', role: 'owner', isCurrentUser: true },
            {
                id: 2,
                name: 'Jane Admin',
                email: 'jane@example.com',
                role: 'admin',
                isCurrentUser: false,
                updateRoleUrl: '/team/members/2/role',
                removeUrl: '/team/members/2',
            },
        ],
        currentUserRole: 'owner',
        canManageMembers: true,
        canManageInvitations: true,
        isInstanceAdmin: false,
        isTransactionalEmailsEnabled: true,
        invitations: [],
        sendInvitationUrl: '/team/invitations',
        ...overrides,
    };
}

describe('Team/Member/Index', () => {
    beforeEach(() => {
        postSpy.mockClear();
        putSpy.mockClear();
        deleteSpy.mockClear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('generates an invitation link via router.post with via: link, not useForm.post', () => {
        render(<Index {...baseProps()} />);

        act(() => typeInto(screen.getByLabelText('Email'), 'invitee@example.com'));
        act(() => screen.getByRole('button', { name: 'Generate Invitation Link' }).click());

        expect(postSpy).toHaveBeenCalledWith(
            '/team/invitations',
            expect.objectContaining({ email: 'invitee@example.com', role: 'member', via: 'link' }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('sends an invitation via email using via: email', () => {
        render(<Index {...baseProps()} />);

        act(() => typeInto(screen.getByLabelText('Email'), 'invitee@example.com'));
        act(() => screen.getByRole('button', { name: 'Send Invitation via Email' }).click());

        expect(postSpy).toHaveBeenCalledWith(
            '/team/invitations',
            expect.objectContaining({ email: 'invitee@example.com', role: 'member', via: 'email' }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('hides the Send Invitation via Email button when transactional email is disabled', () => {
        render(<Index {...baseProps({ isTransactionalEmailsEnabled: false })} />);
        expect(screen.queryByRole('button', { name: 'Send Invitation via Email' })).not.toBeInTheDocument();
    });

    it('shows an owner-only Owner role option only for owners', () => {
        const { unmount } = render(<Index {...baseProps({ currentUserRole: 'owner' })} />);
        expect(within(screen.getByLabelText('Role')).getByRole('option', { name: 'Owner' })).toBeInTheDocument();
        unmount();

        render(<Index {...baseProps({ currentUserRole: 'admin', canManageMembers: false })} />);
        expect(screen.queryByRole('option', { name: 'Owner' })).not.toBeInTheDocument();
    });

    it('updates a member role via router.put', () => {
        render(<Index {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'To Member' }).click());

        expect(putSpy).toHaveBeenCalledWith('/team/members/2/role', { role: 'member' }, expect.objectContaining({ preserveScroll: true }));
    });

    it('removes a member via router.delete only after confirming', () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);
        render(<Index {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'Remove' }).click());
        expect(deleteSpy).not.toHaveBeenCalled();

        window.confirm.mockReturnValue(true);
        act(() => screen.getByRole('button', { name: 'Remove' }).click());
        expect(deleteSpy).toHaveBeenCalledWith('/team/members/2', expect.objectContaining({ preserveScroll: true }));
    });

    it('shows "(This is you)" for the current user instead of role actions', () => {
        render(<Index {...baseProps()} />);
        expect(screen.getByText('(This is you)')).toBeInTheDocument();
    });

    it('does not render the Pending Invitations table when there are none', () => {
        render(<Index {...baseProps({ invitations: [] })} />);
        expect(screen.queryByText('Pending Invitations')).not.toBeInTheDocument();
    });

    it('renders pending invitations and revokes one via router.delete only after confirming', () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        render(
            <Index
                {...baseProps({
                    invitations: [
                        {
                            id: 9,
                            email: 'pending@example.com',
                            via: 'link',
                            role: 'member',
                            link: 'https://example.test/invite/abc',
                            deleteUrl: '/team/invitations/9',
                        },
                    ],
                })}
            />,
        );

        expect(screen.getByText('Pending Invitations')).toBeInTheDocument();
        expect(screen.getByText('pending@example.com')).toBeInTheDocument();

        act(() => screen.getByRole('button', { name: 'Revoke Invitation' }).click());

        expect(deleteSpy).toHaveBeenCalledWith('/team/invitations/9', expect.objectContaining({ preserveScroll: true }));
    });

    it('copies the invitation link to the clipboard', () => {
        const writeText = vi.fn();
        Object.assign(navigator, { clipboard: { writeText } });

        render(
            <Index
                {...baseProps({
                    invitations: [
                        {
                            id: 9,
                            email: 'pending@example.com',
                            via: 'link',
                            role: 'member',
                            link: 'https://example.test/invite/abc',
                            deleteUrl: '/team/invitations/9',
                        },
                    ],
                })}
            />,
        );

        act(() => screen.getByRole('button', { name: 'Copy Invitation Link' }).click());

        expect(writeText).toHaveBeenCalledWith('https://example.test/invite/abc');
    });

    it('toggles the invitation link visibility between password and text', () => {
        render(
            <Index
                {...baseProps({
                    invitations: [
                        {
                            id: 9,
                            email: 'pending@example.com',
                            via: 'link',
                            role: 'member',
                            link: 'https://example.test/invite/abc',
                            deleteUrl: '/team/invitations/9',
                        },
                    ],
                })}
            />,
        );

        const linkInput = document.getElementById('invitation-9-link');
        expect(linkInput.type).toBe('password');

        act(() => screen.getByRole('button', { name: 'Show' }).click());
        expect(linkInput.type).toBe('text');
    });

    it('hides Invite New Member and Pending Invitations sections when canManageInvitations is false', () => {
        render(
            <Index
                {...baseProps({
                    canManageInvitations: false,
                    invitations: [{ id: 9, email: 'x@example.com', via: 'link', role: 'member', link: 'x', deleteUrl: '/x' }],
                })}
            />,
        );
        expect(screen.queryByText('Invite New Member')).not.toBeInTheDocument();
        expect(screen.queryByText('Pending Invitations')).not.toBeInTheDocument();
    });

    it('shows the Admin View nav link only for instance admins', () => {
        const { unmount } = render(<Index {...baseProps({ isInstanceAdmin: false })} />);
        expect(screen.queryByRole('link', { name: 'Admin View' })).not.toBeInTheDocument();
        unmount();

        render(<Index {...baseProps({ isInstanceAdmin: true })} />);
        expect(screen.getByRole('link', { name: 'Admin View' })).toBeInTheDocument();
    });
});
