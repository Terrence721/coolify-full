import { render, screen, waitFor } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Show from './Show';

// The General tab, live-verified 2026-07-25 during the Server management smoke test (issue #26)
// against a real throwaway server: non-localhost direct save, the Phase-78 save-before-validate
// fix (confirmed with a real SSH error against a freshly-typed IP), Fetch Server Details, and the
// instant-save build-server toggle all worked correctly end-to-end. This suite locks that
// previously-untested behavior in.

const patchSpy = vi.fn();
const postSpy = vi.fn();
const reloadSpy = vi.fn();
let teamChannelCallback = null;

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            patch: (url, options) => {
                patchSpy(url, options);
                options?.onSuccess?.();
            },
            processing: false,
            errors: {},
        };
    },
    router: {
        post: (url, data, options) => postSpy(url, data, options),
        reload: (options) => reloadSpy(options),
    },
}));

vi.mock('../../hooks/useTeamChannel', () => ({
    useTeamChannel: (events, callback) => {
        teamChannelCallback = callback;
    },
}));

vi.mock('../../Components/ActivityLog', () => ({
    default: ({ header }) => <div data-testid="activity-log">{header}</div>,
}));

vi.mock('../../Components/PasswordConfirmModal', () => ({
    default: ({ title, onDone }) => (
        <div data-testid="password-confirm-modal">
            {title}
            <button type="button" onClick={onDone}>
                Confirm
            </button>
        </div>
    ),
}));

vi.mock('../../Components/ServerNavbar', () => ({ default: () => <div data-testid="server-navbar" /> }));
vi.mock('../../Components/ServerSidebar', () => ({ default: () => <div data-testid="server-sidebar" /> }));

function jsonResponse(data, ok = true) {
    return Promise.resolve({ ok, json: () => Promise.resolve(data) });
}

function baseServer(overrides = {}) {
    return {
        id: 1,
        uuid: 'srv-uuid',
        name: 'my-server',
        description: '',
        ip: '1.2.3.4',
        user: 'root',
        port: 22,
        connectionTimeout: 10,
        wildcardDomain: '',
        serverTimezone: '',
        isLocalhost: false,
        isReachable: true,
        isUsable: true,
        isFunctional: true,
        isValidating: false,
        isBuildServer: false,
        isBuildServerLocked: false,
        isForceDisabled: false,
        validationLogs: null,
        serverMetadata: null,
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        serverNavbar: {},
        sidebar: {},
        timezones: ['UTC', 'America/New_York'],
        isCloud: false,
        urls: {
            update: '/server/srv-uuid',
            instantSaveBuildServer: '/server/srv-uuid/build-server',
            checkLocalhost: '/server/localhost/check',
            refreshMetadata: '/server/srv-uuid/refresh-metadata',
            validate: '/server/srv-uuid/validate',
        },
        ...overrides,
        server: baseServer(overrides.server),
    };
}

describe('Server/Show', () => {
    beforeEach(() => {
        patchSpy.mockClear();
        postSpy.mockClear();
        reloadSpy.mockClear();
        teamChannelCallback = null;
        global.fetch = vi.fn(() => jsonResponse({}));
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('renders the General heading and Save button', () => {
        render(<Show {...baseProps()} />);
        expect(screen.getByText('General')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save' })).toBeInTheDocument();
    });

    it('saves directly for a non-localhost server, with no password-confirm modal', () => {
        render(<Show {...baseProps()} />);
        act(() => screen.getByRole('button', { name: 'Save' }).click());

        expect(patchSpy).toHaveBeenCalledWith('/server/srv-uuid', undefined);
        expect(screen.queryByTestId('password-confirm-modal')).not.toBeInTheDocument();
    });

    it('shows the password-confirm modal instead of saving directly for the localhost (id 0) server', () => {
        render(<Show {...baseProps({ server: { id: 0 } })} />);
        act(() => screen.getByRole('button', { name: 'Save' }).click());

        expect(patchSpy).not.toHaveBeenCalled();
        expect(screen.getByTestId('password-confirm-modal')).toBeInTheDocument();
        expect(screen.getByText('Confirm Server Settings Change?')).toBeInTheDocument();
    });

    it('shows "Validate Server & Install Docker Engine" when the server needs validation', () => {
        render(<Show {...baseProps({ server: { isReachable: false, isUsable: false, isFunctional: false } })} />);
        expect(screen.getByRole('button', { name: 'Validate Server & Install Docker Engine' })).toBeInTheDocument();
        expect(screen.queryByText('Revalidate server')).not.toBeInTheDocument();
    });

    it('shows "Revalidate server" instead, once the server is functional', () => {
        render(<Show {...baseProps({ server: { isReachable: true, isUsable: true, isFunctional: true } })} />);
        expect(screen.getByText('Revalidate server')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Validate Server & Install Docker Engine' })).not.toBeInTheDocument();
    });

    it('shows the plain "Validate Server" button for an unreachable localhost server, and it posts to checkLocalhost', () => {
        render(<Show {...baseProps({ server: { id: 0, isReachable: false, isUsable: false, isFunctional: false } })} />);
        const btn = screen.getByRole('button', { name: 'Validate Server' });
        act(() => btn.click());
        expect(postSpy).toHaveBeenCalledWith('/server/localhost/check', undefined, undefined);
    });

    it('Revalidate server saves the form first (patch), before triggering validation', async () => {
        render(<Show {...baseProps({ server: { isReachable: true, isUsable: true, isFunctional: true } })} />);
        await act(async () => {
            screen.getByText('Revalidate server').click();
        });

        expect(patchSpy).toHaveBeenCalledWith('/server/srv-uuid', expect.objectContaining({ preserveScroll: true, onSuccess: expect.any(Function) }));
        // The mocked patch() invokes onSuccess synchronously, which is what actually fires the
        // validate fetch - confirming save-then-validate ordering, the exact Phase-78 fix.
        await waitFor(() => expect(global.fetch).toHaveBeenCalledWith('/server/srv-uuid/validate', expect.any(Object)));
    });

    it('toggles the build-server checkbox with an instant save (fetch + empty-key reload), no page navigation', async () => {
        render(<Show {...baseProps()} />);
        const checkbox = screen.getByRole('checkbox', { name: /Use it as a build server\?/ });

        await act(async () => {
            checkbox.click();
        });

        expect(global.fetch).toHaveBeenCalledWith(
            '/server/srv-uuid/build-server',
            expect.objectContaining({ body: JSON.stringify({ isBuildServer: true }) }),
        );
        expect(reloadSpy).toHaveBeenCalledWith({ only: [] });
    });

    it('hides the build-server checkbox entirely for a localhost server', () => {
        render(<Show {...baseProps({ server: { isLocalhost: true } })} />);
        expect(screen.queryByRole('checkbox', { name: /Use it as a build server\?/ })).not.toBeInTheDocument();
    });

    it('disables the build-server checkbox and explains why, once it is locked', () => {
        render(<Show {...baseProps({ server: { isBuildServerLocked: true } })} />);
        expect(screen.getByRole('checkbox', { name: /Use it as a build server\?/ })).toBeDisabled();
        expect(screen.getByText('(locked — this server has defined resources)')).toBeInTheDocument();
    });

    it('hides the Wildcard Domain field once the server is a build server', () => {
        const { unmount } = render(<Show {...baseProps({ server: { isBuildServer: false } })} />);
        expect(screen.getByLabelText('Wildcard Domain')).toBeInTheDocument();
        unmount();

        render(<Show {...baseProps({ server: { isBuildServer: true } })} />);
        expect(screen.queryByLabelText('Wildcard Domain')).not.toBeInTheDocument();
    });

    it('disables every field while the server is validating', () => {
        render(<Show {...baseProps({ server: { isValidating: true } })} />);
        expect(screen.getByLabelText('Name')).toBeDisabled();
        expect(screen.getByLabelText('IP Address/Domain')).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });

    it('shows a "Fetch Server Details" button with no metadata, and the real metadata grid once it exists', () => {
        const { rerender } = render(<Show {...baseProps({ server: { serverMetadata: null } })} />);
        expect(screen.getByRole('button', { name: 'Fetch Server Details' })).toBeInTheDocument();

        rerender(
            <Show
                {...baseProps({
                    server: {
                        serverMetadata: {
                            os: 'Debian GNU/Linux 12 (bookworm)',
                            arch: 'x86_64',
                            kernel: '6.18.33.1-microsoft-standard-WSL2',
                            cpus: 8,
                            memory_bytes: 8158912512,
                            uptime_since: '2026-07-25 16:14:47',
                        },
                    },
                })}
            />,
        );
        expect(screen.getByText('Debian GNU/Linux 12 (bookworm)')).toBeInTheDocument();
        expect(screen.getByText('x86_64')).toBeInTheDocument();
        expect(screen.getByText('8')).toBeInTheDocument();
        expect(screen.getByText('7.6 GB')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Fetch Server Details' })).not.toBeInTheDocument();
    });

    it('clicking Fetch Server Details posts to refreshMetadata', () => {
        render(<Show {...baseProps()} />);
        act(() => screen.getByRole('button', { name: 'Fetch Server Details' }).click());
        expect(postSpy).toHaveBeenCalledWith('/server/srv-uuid/refresh-metadata', undefined, undefined);
    });

    it('reloads the server prop when a matching ServerValidated event arrives on the team channel', () => {
        render(<Show {...baseProps()} />);
        act(() => teamChannelCallback('.ServerValidated', { serverUuid: 'srv-uuid' }));
        expect(reloadSpy).toHaveBeenCalledWith({ only: ['server'] });
    });

    it('ignores a ServerValidated event for a different server', () => {
        render(<Show {...baseProps()} />);
        act(() => teamChannelCallback('.ServerValidated', { serverUuid: 'some-other-uuid' }));
        expect(reloadSpy).not.toHaveBeenCalled();
    });
});
