import { render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DynamicConfigurations from './DynamicConfigurations';

// Live-verified 2026-07-26 during the Server management smoke test (issue #26): reserved files
// (Caddyfile, default_redirect_503.yaml) correctly render read-only with no Edit/Delete controls;
// "+ Add" created a real throwaway file (verified directly on the real managed server's disk),
// "Edit" opened pre-filled, "Reload" then "Delete" removed it (re-confirmed gone from real disk).
// MonacoEditor is mocked out - it's a separate, not-yet-covered backlog item on its own.

const postSpy = vi.fn();
const reloadSpy = vi.fn();
const deleteSpy = vi.fn();
let teamChannelCallback = null;

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            post: (url, options) => {
                postSpy(url, options, data);
                options?.onSuccess?.();
            },
            processing: false,
            errors: {},
        };
    },
    router: {
        reload: (options) => reloadSpy(options),
        delete: (url, options) => deleteSpy(url, options),
    },
}));

vi.mock('../../../hooks/useTeamChannel', () => ({
    useTeamChannel: (events, callback) => {
        teamChannelCallback = callback;
    },
}));

vi.mock('../../../Components/ServerNavbar', () => ({ default: () => <div data-testid="server-navbar" /> }));
vi.mock('../../../Components/ServerSidebar', () => ({ default: () => <div data-testid="server-sidebar" /> }));
vi.mock('../../../Components/MonacoEditor', () => ({
    default: ({ value, onChange, readOnly }) => (
        <textarea data-testid="monaco" readOnly={!!readOnly} value={value} onChange={(e) => onChange?.(e.target.value)} />
    ),
}));

function baseProps(overrides = {}) {
    return {
        serverNavbar: {},
        sidebar: {},
        isFunctional: true,
        canUpdate: true,
        contents: [],
        storeUrl: '/server/srv-uuid/proxy/dynamic',
        deleteUrl: '/server/srv-uuid/proxy/dynamic/delete',
        ...overrides,
    };
}

describe('Server/Proxy/DynamicConfigurations', () => {
    beforeEach(() => {
        postSpy.mockClear();
        reloadSpy.mockClear();
        deleteSpy.mockClear();
        teamChannelCallback = null;
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('renders nothing when the server is not functional', () => {
        render(<DynamicConfigurations {...baseProps({ isFunctional: false })} />);
        expect(screen.queryByText('Dynamic Configurations')).not.toBeInTheDocument();
    });

    it('shows the empty state when there are no files', () => {
        render(<DynamicConfigurations {...baseProps({ contents: [] })} />);
        expect(screen.getByText('No dynamic configurations found.')).toBeInTheDocument();
    });

    it('renders a reserved file as a disabled, read-only textarea with no Edit/Delete controls', () => {
        render(<DynamicConfigurations {...baseProps({ contents: [{ fileName: 'Caddyfile', value: 'import /dynamic/*.caddy' }] })} />);
        expect(screen.getByText('File: Caddyfile')).toBeInTheDocument();
        const textarea = document.getElementById('dynamic-configuration-Caddyfile');
        expect(textarea).toBeDisabled();
        expect(textarea).toHaveAttribute('readonly');
        expect(textarea).toHaveValue('import /dynamic/*.caddy');
        expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument();
    });

    it('renders a non-reserved file with Edit/Delete when canUpdate is true', () => {
        render(<DynamicConfigurations {...baseProps({ contents: [{ fileName: 'custom.yaml', value: 'http: {}' }] })} />);
        expect(screen.getByText('File: custom.yaml')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Delete' })).toBeInTheDocument();
    });

    it('hides Edit/Delete/+ Add when canUpdate is false, even for a non-reserved file', () => {
        render(<DynamicConfigurations {...baseProps({ canUpdate: false, contents: [{ fileName: 'custom.yaml', value: 'http: {}' }] })} />);
        expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: '+ Add' })).not.toBeInTheDocument();
    });

    it('reloads only contents when Reload is clicked', () => {
        render(<DynamicConfigurations {...baseProps()} />);
        act(() => screen.getByRole('button', { name: 'Reload' }).click());
        expect(reloadSpy).toHaveBeenCalledWith({ only: ['contents'] });
    });

    it('reloads the same way when a real ProxyStatusChangedUI event arrives on the team channel', () => {
        render(<DynamicConfigurations {...baseProps()} />);
        act(() => teamChannelCallback('ProxyStatusChangedUI', {}));
        expect(reloadSpy).toHaveBeenCalledWith({ only: ['contents'] });
    });

    it('opens the "New Dynamic Configuration" modal via + Add, empty by default', () => {
        render(<DynamicConfigurations {...baseProps()} />);
        act(() => screen.getByRole('button', { name: '+ Add' }).click());
        expect(screen.getByText('New Dynamic Configuration')).toBeInTheDocument();
        expect(screen.getByLabelText('Filename')).toHaveValue('');
    });

    it('closes the Add modal via the ✕ button and via the backdrop click', () => {
        render(<DynamicConfigurations {...baseProps()} />);
        act(() => screen.getByRole('button', { name: '+ Add' }).click());

        act(() => screen.getByRole('button', { name: '✕' }).click());
        expect(screen.queryByText('New Dynamic Configuration')).not.toBeInTheDocument();

        act(() => screen.getByRole('button', { name: '+ Add' }).click());
        const backdrop = document.querySelector('.absolute.inset-0.h-full.w-full');
        act(() => backdrop.click());
        expect(screen.queryByText('New Dynamic Configuration')).not.toBeInTheDocument();
    });

    it('submits the new-file form with newFile: true and closes on success', () => {
        render(<DynamicConfigurations {...baseProps()} />);
        act(() => screen.getByRole('button', { name: '+ Add' }).click());

        const filenameInput = screen.getByLabelText('Filename');
        act(() => {
            const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
            setter.call(filenameInput, 'smoketest-throwaway.yaml');
            filenameInput.dispatchEvent(new Event('input', { bubbles: true }));
        });
        act(() => screen.getByRole('button', { name: 'Save' }).click());

        expect(postSpy).toHaveBeenCalledWith(
            '/server/srv-uuid/proxy/dynamic',
            expect.objectContaining({ preserveScroll: true, onSuccess: expect.any(Function) }),
            expect.objectContaining({ fileName: 'smoketest-throwaway.yaml', newFile: true }),
        );
        expect(screen.queryByText('New Dynamic Configuration')).not.toBeInTheDocument();
    });

    it('opens "Edit Configuration" pre-filled with the real file name and content', () => {
        render(<DynamicConfigurations {...baseProps({ contents: [{ fileName: 'custom.yaml', value: 'http: {}' }] })} />);
        act(() => screen.getByRole('button', { name: 'Edit' }).click());

        expect(screen.getByText('Edit Configuration')).toBeInTheDocument();
        expect(screen.getByLabelText('Filename')).toHaveValue('custom.yaml');
        const monacoEditors = screen.getAllByTestId('monaco');
        expect(monacoEditors[monacoEditors.length - 1]).toHaveValue('http: {}');
    });

    it('submits the edit form with newFile: false', () => {
        render(<DynamicConfigurations {...baseProps({ contents: [{ fileName: 'custom.yaml', value: 'http: {}' }] })} />);
        act(() => screen.getByRole('button', { name: 'Edit' }).click());
        act(() => screen.getByRole('button', { name: 'Save' }).click());

        expect(postSpy).toHaveBeenCalledWith(
            '/server/srv-uuid/proxy/dynamic',
            expect.objectContaining({ preserveScroll: true }),
            expect.objectContaining({ fileName: 'custom.yaml', value: 'http: {}', newFile: false }),
        );
    });

    it('calls router.delete(deleteUrl) with the fileName when Delete is clicked', () => {
        render(<DynamicConfigurations {...baseProps({ contents: [{ fileName: 'custom.yaml', value: 'http: {}' }] })} />);
        act(() => screen.getByRole('button', { name: 'Delete' }).click());

        expect(deleteSpy).toHaveBeenCalledWith('/server/srv-uuid/proxy/dynamic/delete', {
            data: { fileName: 'custom.yaml' },
            preserveScroll: true,
        });
    });
});
