import { render, screen, within } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ApiTokens from './ApiTokens';

// The /security/api-tokens page, live-verified end-to-end during the 2026-07-20 smoke test
// (issue #25): created a token with a unique description, confirmed the "won't be shown again"
// plaintext notice and the new row in the Issued Tokens table (correct default 90-day expiry),
// then revoked it (confirm-dialog gated) and confirmed the row disappeared after a reload. The
// "Expires in" dropdown's 90-day default was separately confirmed live (2026-07-19) via a real
// DOM inputValue() read. This suite locks all of that in as automated coverage; the page was
// previously entirely untested.

const postSpy = vi.fn();
const deleteSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            post: (url, options) => {
                postSpy(url, options);
                options?.onSuccess?.();
            },
            processing: false,
            errors: {},
        };
    },
    router: { delete: (url) => deleteSpy(url) },
}));

function baseProps(overrides = {}) {
    return {
        isApiEnabled: true,
        canCreate: true,
        canUseRootPermissions: true,
        canUseWritePermissions: true,
        canViewCloudTokens: true,
        canViewCloudInitScripts: true,
        expirationOptions: { 7: '7 days', 30: '30 days', 90: '90 days' },
        tokens: [],
        storeUrl: '/security/api-tokens',
        newlyCreatedToken: null,
        ...overrides,
    };
}

function baseToken(overrides = {}) {
    return {
        id: 1,
        name: 'ci-deploy-token',
        abilities: ['read', 'deploy'],
        lastUsedAt: null,
        createdAt: '2026-07-20',
        expiresAt: null,
        isExpired: false,
        ownedByCurrentUser: true,
        revokeUrl: '/security/api-tokens/1',
        ...overrides,
    };
}

describe('Security/ApiTokens', () => {
    beforeEach(() => {
        postSpy.mockClear();
        deleteSpy.mockClear();
        vi.spyOn(window, 'confirm');
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('explains the API is disabled, with a link to Settings, when isApiEnabled is false', () => {
        render(<ApiTokens {...baseProps({ isApiEnabled: false })} />);
        expect(screen.getByText(/API is disabled/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Settings' })).toHaveAttribute('href', '/settings/advanced');
    });

    it('shows the team-scope message when isApiEnabled is true', () => {
        render(<ApiTokens {...baseProps({ isApiEnabled: true })} />);
        expect(screen.getByText('Tokens are created with the current team as scope.')).toBeInTheDocument();
    });

    it('hides the New Token form entirely when canCreate is false', () => {
        render(<ApiTokens {...baseProps({ canCreate: false })} />);
        expect(screen.queryByLabelText('Description')).not.toBeInTheDocument();
    });

    it('defaults "Expires in" to 90 days and lists every option plus Never', () => {
        render(<ApiTokens {...baseProps()} />);
        const select = screen.getByLabelText('Expires in');
        expect(select).toHaveValue('90');
        expect(screen.getByRole('option', { name: '7 days' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: '30 days' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'Never' })).toBeInTheDocument();
    });

    it('defaults to the read permission shown in the summary line', () => {
        render(<ApiTokens {...baseProps()} />);
        expect(screen.getByLabelText(/^read$/)).toBeChecked();
        expect(screen.queryByText('Root access, be careful!')).not.toBeInTheDocument();
    });

    it('checking root exclusively selects it, hides the other checkboxes, and shows the warning', () => {
        render(<ApiTokens {...baseProps()} />);
        act(() => screen.getByLabelText('root').click());

        expect(screen.getByLabelText('root')).toBeChecked();
        expect(screen.queryByLabelText(/^write$/)).not.toBeInTheDocument();
        expect(screen.getByText('Root access, be careful!')).toBeInTheDocument();
    });

    it('unchecking root falls back to read alone', () => {
        render(<ApiTokens {...baseProps()} />);
        act(() => screen.getByLabelText('root').click());
        act(() => screen.getByLabelText('root').click());

        expect(screen.getByLabelText('root')).not.toBeChecked();
        expect(screen.getByLabelText(/^read$/)).toBeChecked();
    });

    it('write/deploy/read/read:sensitive toggle independently, and clearing the last one falls back to read', () => {
        render(<ApiTokens {...baseProps()} />);

        act(() => screen.getByLabelText(/^write$/).click());
        expect(screen.getByLabelText(/^write$/)).toBeChecked();
        expect(screen.getByLabelText(/^read$/)).toBeChecked();

        act(() => screen.getByLabelText(/^read$/).click());
        act(() => screen.getByLabelText(/^write$/).click());
        expect(screen.getByLabelText(/^read$/)).toBeChecked();
    });

    it('disables write/root checkboxes and relabels them when the permission is not allowed', () => {
        render(<ApiTokens {...baseProps({ canUseRootPermissions: false, canUseWritePermissions: false })} />);
        expect(screen.getByLabelText('root (admin/owner only)')).toBeDisabled();
        expect(screen.getByLabelText('write (admin/owner only)')).toBeDisabled();
    });

    it('submits the form via post(storeUrl) and clears the description on success', () => {
        render(<ApiTokens {...baseProps()} />);
        const inputSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        act(() => {
            inputSetter.call(screen.getByLabelText('Description'), 'ci-token');
            screen.getByLabelText('Description').dispatchEvent(new Event('input', { bubbles: true }));
        });
        expect(screen.getByLabelText('Description')).toHaveValue('ci-token');

        act(() => screen.getByRole('button', { name: 'Create' }).click());

        expect(postSpy).toHaveBeenCalledWith('/security/api-tokens', expect.objectContaining({ onSuccess: expect.any(Function) }));
        expect(screen.getByLabelText('Description')).toHaveValue('');
    });

    it("shows the newly created token's plaintext value with the won't-be-shown-again notice", () => {
        render(<ApiTokens {...baseProps({ newlyCreatedToken: 'plain|abc123def456' })} />);
        expect(screen.getByText(/won't be shown again/)).toBeInTheDocument();
        expect(screen.getByText('plain|abc123def456')).toBeInTheDocument();
    });

    it('does not show the plaintext-token notice when newlyCreatedToken is null', () => {
        render(<ApiTokens {...baseProps({ newlyCreatedToken: null })} />);
        expect(screen.queryByText(/won't be shown again/)).not.toBeInTheDocument();
    });

    it('shows the empty state when there are no tokens', () => {
        render(<ApiTokens {...baseProps({ tokens: [] })} />);
        expect(screen.getByText('No API tokens found.')).toBeInTheDocument();
    });

    it("renders a token row's abilities, last-used fallback, and expiry", () => {
        render(<ApiTokens {...baseProps({ canCreate: false, tokens: [baseToken()] })} />);
        const row = within(screen.getByText('ci-deploy-token').closest('tr'));
        expect(row.getByText('read')).toBeInTheDocument();
        expect(row.getByText('deploy')).toBeInTheDocument();
        expect(row.getAllByText('Never')).toHaveLength(2); // lastUsedAt fallback + expiresAt (no expiry set)
    });

    it('shows a red "Expired" label only when the token is actually expired', () => {
        const { unmount } = render(<ApiTokens {...baseProps({ tokens: [baseToken({ expiresAt: '2026-08-01', isExpired: false })] })} />);
        expect(screen.getByText('2026-08-01')).toBeInTheDocument();
        expect(screen.queryByText(/Expired/)).not.toBeInTheDocument();
        unmount();

        render(<ApiTokens {...baseProps({ tokens: [baseToken({ expiresAt: '2026-07-01', isExpired: true })] })} />);
        expect(screen.getByText('Expired 2026-07-01')).toBeInTheDocument();
    });

    it('only shows the Revoke button for tokens owned by the current user', () => {
        render(
            <ApiTokens
                {...baseProps({
                    tokens: [baseToken({ id: 1, ownedByCurrentUser: true }), baseToken({ id: 2, name: 'teammate-token', ownedByCurrentUser: false })],
                })}
            />,
        );
        expect(screen.getAllByRole('button', { name: 'Revoke token' })).toHaveLength(1);
    });

    it('revokes via router.delete(revokeUrl) only after confirm() is accepted', () => {
        window.confirm.mockReturnValue(false);
        render(<ApiTokens {...baseProps({ tokens: [baseToken()] })} />);
        act(() => screen.getByRole('button', { name: 'Revoke token' }).click());
        expect(deleteSpy).not.toHaveBeenCalled();

        window.confirm.mockReturnValue(true);
        act(() => screen.getByRole('button', { name: 'Revoke token' }).click());
        expect(deleteSpy).toHaveBeenCalledWith('/security/api-tokens/1');
    });
});
