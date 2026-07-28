import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Proxy from './Proxy';

// React 19 patches the native <input> value setter to track controlled-component state - directly
// assigning `.value` then dispatching a bare event doesn't notify it. Using the real native setter
// first (bypassing React's patched one) is the standard workaround absent
// @testing-library/user-event, which isn't installed in this project.
function typeInto(element, value) {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

// Live-verified end-to-end during the 2026-07-28 Server management smoke test (issue #26)
// against production-01: cold-load Monaco render + theme sync, the blocked-toast vs. real
// confirm-modal Switch Proxy split (running vs. stopped), the DB-only proxy-selection reset,
// Reset Configuration's typed-confirmation gate, and the Traefik version-warning
// dismiss/localStorage persistence (both warning types share one dismiss state) - all
// previously untested at the component level. MonacoEditor is mocked out, matching the
// existing DynamicConfigurations.test.jsx convention - it's its own separate backlog item.

const postSpy = vi.fn();
const toastSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => postSpy(url, data, options),
    },
}));

vi.mock('../../Components/ServerNavbar', () => ({ default: () => <div data-testid="server-navbar" /> }));
vi.mock('../../Components/ServerSidebar', () => ({ default: () => <div data-testid="server-sidebar" /> }));
vi.mock('../../Components/MonacoEditor', () => ({
    default: ({ value, onChange, readOnly }) => (
        <textarea data-testid="monaco" readOnly={!!readOnly} value={value} onChange={(e) => onChange?.(e.target.value)} />
    ),
}));

function baseProps(overrides = {}) {
    return {
        serverNavbar: { server: { id: 1, name: 'production-01' } },
        sidebar: {},
        canUpdate: true,
        selectedProxy: 'TRAEFIK',
        proxyStatus: 'running',
        proxyOutOfSync: false,
        proxySettings: 'name: coolify-proxy\nservices:\n  traefik:\n    image: traefik:v3.1.2\n',
        configurationFilePath: '/data/coolify/proxy/docker-compose.yml',
        generateExactLabels: false,
        redirectEnabled: false,
        redirectUrl: '',
        detectedTraefikVersion: '3.1.2',
        latestTraefikVersion: 'v3.1.2',
        isTraefikOutdated: false,
        newerTraefikBranchAvailable: null,
        selectProxyUrl: '/server/srv-uuid/proxy/select',
        resetProxySelectionUrl: '/server/srv-uuid/proxy/reset-selection',
        instantSaveUrl: '/server/srv-uuid/proxy/instant-save',
        instantSaveRedirectUrl: '/server/srv-uuid/proxy/instant-save-redirect',
        submitUrl: '/server/srv-uuid/proxy/submit',
        resetConfigurationUrl: '/server/srv-uuid/proxy/reset-configuration',
        ...overrides,
    };
}

describe('Server/Proxy', () => {
    beforeEach(() => {
        postSpy.mockClear();
        toastSpy.mockClear();
        window.toast = toastSpy;
        localStorage.clear();
    });

    afterEach(() => {
        delete window.toast;
        vi.restoreAllMocks();
    });

    describe('no proxy selected', () => {
        it('shows the proxy-type-selection screen with all 3 buttons when canUpdate is true', () => {
            render(<Proxy {...baseProps({ selectedProxy: null })} />);
            expect(screen.getByText('Select a proxy you would like to use on this server.')).toBeInTheDocument();
            expect(screen.getByRole('button', { name: 'Custom (None)' })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: 'Traefik', exact: true })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: 'Caddy' })).toBeInTheDocument();
        });

        it('shows a permission-denied message instead of the buttons when canUpdate is false', () => {
            render(<Proxy {...baseProps({ selectedProxy: null, canUpdate: false })} />);
            expect(screen.getByText("You don't have permission to configure proxy settings for this server.")).toBeInTheDocument();
            expect(screen.queryByRole('button', { name: 'Traefik', exact: true })).not.toBeInTheDocument();
        });

        it('selecting a type posts {proxy_type} to selectProxyUrl', () => {
            render(<Proxy {...baseProps({ selectedProxy: null })} />);
            act(() => screen.getByRole('button', { name: 'Caddy' }).click());
            expect(postSpy).toHaveBeenCalledWith('/server/srv-uuid/proxy/select', { proxy_type: 'CADDY' }, { preserveScroll: true });
        });
    });

    describe('NONE selected', () => {
        it('shows "Custom (None) Proxy Selected" and a Switch Proxy button when canUpdate', () => {
            render(<Proxy {...baseProps({ selectedProxy: 'NONE' })} />);
            expect(screen.getByText('Custom (None) Proxy Selected')).toBeInTheDocument();
            expect(screen.getByRole('button', { name: 'Switch Proxy' })).toBeInTheDocument();
        });

        it('hides the Switch Proxy button when canUpdate is false', () => {
            render(<Proxy {...baseProps({ selectedProxy: 'NONE', canUpdate: false })} />);
            expect(screen.queryByRole('button', { name: 'Switch Proxy' })).not.toBeInTheDocument();
        });
    });

    describe('Traefik/Caddy configuration form', () => {
        it('shows the proxyOutOfSync warning only when true', () => {
            const { rerender } = render(<Proxy {...baseProps({ proxyOutOfSync: false })} />);
            expect(screen.queryByText(/currently running configuration/)).not.toBeInTheDocument();

            rerender(<Proxy {...baseProps({ proxyOutOfSync: true })} />);
            expect(screen.getByText(/currently running configuration/)).toBeInTheDocument();
        });

        it('toggles generateExactLabels via instant-save, labeled for the active proxy type', () => {
            render(<Proxy {...baseProps({ selectedProxy: 'CADDY', generateExactLabels: false })} />);
            expect(screen.getByText('Generate labels only for Caddy')).toBeInTheDocument();

            act(() => screen.getByLabelText('Generate labels only for Caddy').click());
            expect(postSpy).toHaveBeenCalledWith('/server/srv-uuid/proxy/instant-save', { generateExactLabels: true }, { preserveScroll: true });
        });

        it('disables the Advanced checkboxes when canUpdate is false', () => {
            render(<Proxy {...baseProps({ canUpdate: false })} />);
            expect(screen.getByLabelText('Generate labels only for Traefik')).toBeDisabled();
            expect(screen.getByLabelText('Override default request handler')).toBeDisabled();
        });

        it('shows the redirect URL field only once redirectEnabled is checked, and toggling posts to instantSaveRedirectUrl', () => {
            render(<Proxy {...baseProps({ redirectEnabled: false })} />);
            expect(screen.queryByLabelText('Redirect to (optional)')).not.toBeInTheDocument();

            act(() => screen.getByLabelText('Override default request handler').click());
            expect(postSpy).toHaveBeenCalledWith('/server/srv-uuid/proxy/instant-save-redirect', { redirectEnabled: true }, { preserveScroll: true });
        });

        it('renders the redirect URL field pre-filled and its error when redirectEnabled is already true', () => {
            render(<Proxy {...baseProps({ redirectEnabled: true, redirectUrl: 'https://example.com' })} />);
            expect(screen.getByLabelText('Redirect to (optional)')).toHaveValue('https://example.com');
        });

        it('submits proxySettings + redirectUrl to submitUrl on Save, disabling the button while submitting', () => {
            render(<Proxy {...baseProps({ redirectUrl: 'https://old.example.com' })} />);
            act(() => screen.getByRole('button', { name: 'Save' }).click());

            expect(postSpy).toHaveBeenCalledWith(
                '/server/srv-uuid/proxy/submit',
                { proxySettings: 'name: coolify-proxy\nservices:\n  traefik:\n    image: traefik:v3.1.2\n', redirectUrl: 'https://old.example.com' },
                expect.objectContaining({ preserveScroll: true, onError: expect.any(Function), onFinish: expect.any(Function) }),
            );
            expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
        });

        it('renders validation errors from onError and re-enables Save via onFinish', () => {
            render(<Proxy {...baseProps({ redirectEnabled: true })} />);
            act(() => screen.getByRole('button', { name: 'Save' }).click());

            const options = postSpy.mock.calls.at(-1)[2];
            act(() => options.onError({ redirectUrl: 'The redirect url must be a valid URL.' }));
            expect(screen.getByText('The redirect url must be a valid URL.')).toBeInTheDocument();

            act(() => options.onFinish());
            expect(screen.getByRole('button', { name: 'Save' })).not.toBeDisabled();
        });

        it('shows the blocked toast (no modal) when Switch Proxy is clicked while the proxy is running', () => {
            render(<Proxy {...baseProps({ proxyStatus: 'running' })} />);
            act(() => screen.getByRole('button', { name: 'Switch Proxy' }).click());

            expect(toastSpy).toHaveBeenCalledWith(
                'Error',
                expect.objectContaining({ type: 'danger', description: 'Currently running proxy must be stopped before switching proxy' }),
            );
            expect(screen.queryByText('Confirm Proxy Switching?')).not.toBeInTheDocument();
        });

        it.each(['exited', 'removing'])('opens the confirm modal directly when Switch Proxy is clicked while proxyStatus is %s', (status) => {
            render(<Proxy {...baseProps({ proxyStatus: status })} />);
            act(() => screen.getByRole('button', { name: 'Switch Proxy' }).click());

            expect(screen.getByText('Confirm Proxy Switching?')).toBeInTheDocument();
            expect(toastSpy).not.toHaveBeenCalled();
        });

        it('confirming the switch modal posts to resetProxySelectionUrl and closes the modal', () => {
            render(<Proxy {...baseProps({ proxyStatus: 'exited' })} />);
            act(() => screen.getByRole('button', { name: 'Switch Proxy' }).click());

            const modalConfirm = screen.getAllByRole('button', { name: 'Switch Proxy' })[1];
            act(() => modalConfirm.click());

            expect(postSpy).toHaveBeenCalledWith('/server/srv-uuid/proxy/reset-selection', {}, { preserveScroll: true });
            expect(screen.queryByText('Confirm Proxy Switching?')).not.toBeInTheDocument();
        });

        it('Cancel closes the switch modal without posting anything', () => {
            render(<Proxy {...baseProps({ proxyStatus: 'exited' })} />);
            act(() => screen.getByRole('button', { name: 'Switch Proxy' }).click());
            act(() => screen.getByRole('button', { name: 'Cancel' }).click());

            expect(screen.queryByText('Confirm Proxy Switching?')).not.toBeInTheDocument();
            expect(postSpy).not.toHaveBeenCalled();
        });

        it('shows Reset Configuration only when canUpdate and proxySettings are both truthy', () => {
            // proxySettings seeds a useState initializer read once at mount, so each variant
            // needs its own render() rather than rerender() on the same instance.
            const { unmount: unmount1 } = render(<Proxy {...baseProps({ proxySettings: '' })} />);
            expect(screen.queryByRole('button', { name: 'Reset Configuration' })).not.toBeInTheDocument();
            unmount1();

            const { unmount: unmount2 } = render(<Proxy {...baseProps({ canUpdate: false })} />);
            expect(screen.queryByRole('button', { name: 'Reset Configuration' })).not.toBeInTheDocument();
            unmount2();

            render(<Proxy {...baseProps()} />);
            expect(screen.getByRole('button', { name: 'Reset Configuration' })).toBeInTheDocument();
        });

        it('keeps Reset Configuration disabled until the typed confirmation exactly matches the server name', () => {
            render(<Proxy {...baseProps()} />);
            act(() => screen.getByRole('button', { name: 'Reset Configuration' }).click());

            const confirmInput = screen.getByLabelText('Please confirm by entering the server name below');
            const confirmBtn = screen.getAllByRole('button', { name: 'Reset Configuration' })[1];
            expect(confirmBtn).toBeDisabled();

            act(() => typeInto(confirmInput, 'wrong-name'));
            expect(confirmBtn).toBeDisabled();

            act(() => typeInto(confirmInput, 'production-01'));
            expect(confirmBtn).not.toBeDisabled();

            act(() => confirmBtn.click());
            expect(postSpy).toHaveBeenCalledWith('/server/srv-uuid/proxy/reset-configuration', {}, { preserveScroll: true });
        });

        it('Cancel on the reset modal clears the typed confirmation and closes without posting', () => {
            render(<Proxy {...baseProps()} />);
            act(() => screen.getByRole('button', { name: 'Reset Configuration' }).click());

            const confirmInput = screen.getByLabelText('Please confirm by entering the server name below');
            act(() => typeInto(confirmInput, 'production-01'));

            act(() => screen.getByRole('button', { name: 'Cancel' }).click());
            expect(screen.queryByText('Reset Proxy Configuration?')).not.toBeInTheDocument();
            expect(postSpy).not.toHaveBeenCalled();
        });

        it('renders the Monaco editor with the configuration file path only when proxySettings is present', () => {
            const { unmount } = render(<Proxy {...baseProps()} />);
            expect(screen.getByText(/Configuration file \( \/data\/coolify\/proxy\/docker-compose\.yml \)/)).toBeInTheDocument();
            expect(screen.getByTestId('monaco')).toBeInTheDocument();
            unmount();

            // proxySettings seeds a useState initializer read once at mount - a fresh render(),
            // not rerender(), is required to actually exercise the empty-value branch.
            render(<Proxy {...baseProps({ proxySettings: '' })} />);
            expect(screen.queryByTestId('monaco')).not.toBeInTheDocument();
        });
    });

    describe('Traefik version warnings', () => {
        it('renders nothing when on the latest patch and no newer branch exists', () => {
            render(<Proxy {...baseProps({ isTraefikOutdated: false, detectedTraefikVersion: '3.1.2', newerTraefikBranchAvailable: null })} />);
            expect(screen.queryByRole('button', { name: 'Dismiss' })).not.toBeInTheDocument();
        });

        it('shows the "latest" tag warning when detectedTraefikVersion is "latest"', () => {
            render(<Proxy {...baseProps({ detectedTraefikVersion: 'latest' })} />);
            expect(screen.getByText("Using 'latest' Traefik Tag")).toBeInTheDocument();
        });

        it('shows the patch-update warning when outdated, and the minor-version warning independently, sharing one dismiss state', () => {
            render(
                <Proxy
                    {...baseProps({
                        detectedTraefikVersion: '3.0.0',
                        isTraefikOutdated: true,
                        latestTraefikVersion: 'v3.0.4',
                        newerTraefikBranchAvailable: 'v3.6',
                    })}
                />,
            );
            expect(screen.getByText('Traefik Patch Update Available')).toBeInTheDocument();
            expect(screen.getByText('New Minor Traefik Version Available')).toBeInTheDocument();
            const dismissButtons = screen.getAllByRole('button', { name: 'Dismiss' });
            expect(dismissButtons).toHaveLength(2);

            act(() => dismissButtons[0].click());
            expect(screen.queryByText('Traefik Patch Update Available')).not.toBeInTheDocument();
            expect(screen.queryByText('New Minor Traefik Version Available')).not.toBeInTheDocument();
            expect(localStorage.getItem('callout-dismissed-traefik-warnings-1')).toBe('true');
        });

        it('persists the dismissal across a remount via localStorage, and the show-warnings button restores it', () => {
            localStorage.setItem('callout-dismissed-traefik-warnings-1', 'true');
            render(<Proxy {...baseProps({ detectedTraefikVersion: '3.0.0', isTraefikOutdated: true, latestTraefikVersion: 'v3.0.4' })} />);

            expect(screen.queryByText('Traefik Patch Update Available')).not.toBeInTheDocument();
            const showBtn = screen.getByTitle('Show Traefik warnings');
            expect(showBtn).toBeInTheDocument();

            act(() => showBtn.click());
            expect(screen.getByText('Traefik Patch Update Available')).toBeInTheDocument();
            expect(localStorage.getItem('callout-dismissed-traefik-warnings-1')).toBeNull();
        });

        it('never renders version warnings for Caddy, even with outdated/newer-branch data present', () => {
            render(
                <Proxy
                    {...baseProps({
                        selectedProxy: 'CADDY',
                        detectedTraefikVersion: '3.0.0',
                        isTraefikOutdated: true,
                        newerTraefikBranchAvailable: 'v3.6',
                    })}
                />,
            );
            expect(screen.queryByText('Traefik Patch Update Available')).not.toBeInTheDocument();
            expect(screen.queryByText('New Minor Traefik Version Available')).not.toBeInTheDocument();
            expect(screen.getByText('Caddy (Coolify Proxy)')).toBeInTheDocument();
        });
    });
});
