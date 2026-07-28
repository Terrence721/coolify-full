import { render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CloudflareTunnel from './CloudflareTunnel';

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
// against a real Cloudflare account/tunnel token: automated config genuinely registered 4
// tunnel connections with Cloudflare's real edge, and disable correctly reverted the server's
// IP to ip_previous. Backend regression coverage for ConfigureCloudflared (the action the
// automated form drives) was added separately in ServerCloudflareTunnelTest.php - this suite
// locks in the page's own previously-untested logic: the two independent typed-confirmation
// prompts, the enabled/functional-driven section visibility, the automated-config form, and
// the flash-triggered log modal.

const postSpy = vi.fn();
const routerPostSpy = vi.fn();
let mockFlash = {};
let mockErrors = {};
let mockProcessing = false;

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => routerPostSpy(url, data, options),
    },
    usePage: () => ({ props: { flash: mockFlash } }),
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            post: (url, options) => postSpy(url, data, options),
            processing: mockProcessing,
            errors: mockErrors,
        };
    },
}));

vi.mock('../../Components/ServerNavbar', () => ({ default: () => <div data-testid="server-navbar" /> }));
vi.mock('../../Components/ServerSidebar', () => ({ default: () => <div data-testid="server-sidebar" /> }));
vi.mock('../../Components/ActivityLog', () => ({
    default: ({ activityId, header }) => (
        <div data-testid="activity-log">
            {header} - {activityId}
        </div>
    ),
}));

function baseProps(overrides = {}) {
    return {
        serverNavbar: {},
        sidebar: {},
        isCloudflareTunnelsEnabled: false,
        isFunctional: true,
        canUpdate: true,
        toggleUrl: '/server/srv-uuid/cloudflare-tunnel/toggle',
        manualConfigUrl: '/server/srv-uuid/cloudflare-tunnel/manual-config',
        automatedConfigUrl: '/server/srv-uuid/cloudflare-tunnel/automated-config',
        ...overrides,
    };
}

describe('Server/CloudflareTunnel', () => {
    beforeEach(() => {
        postSpy.mockClear();
        routerPostSpy.mockClear();
        mockFlash = {};
        mockErrors = {};
        mockProcessing = false;
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('renders the heading, tooltip, and subtitle', () => {
        render(<CloudflareTunnel {...baseProps()} />);
        expect(screen.getByText('Cloudflare Tunnel')).toBeInTheDocument();
        expect(screen.getByText('Secure your servers with Cloudflare Tunnel.')).toBeInTheDocument();
        expect(screen.getByTitle(/It will proxy all SSH requests to your server through Cloudflare/)).toBeInTheDocument();
    });

    it('shows the Enabled badge only when isCloudflareTunnelsEnabled is true', () => {
        const { rerender } = render(<CloudflareTunnel {...baseProps({ isCloudflareTunnelsEnabled: false })} />);
        expect(screen.queryByText('Enabled')).not.toBeInTheDocument();

        rerender(<CloudflareTunnel {...baseProps({ isCloudflareTunnelsEnabled: true })} />);
        expect(screen.getByText('Enabled')).toBeInTheDocument();
    });

    it('shows the disable warning and Disable button when enabled, hides the automated section entirely', () => {
        render(<CloudflareTunnel {...baseProps({ isCloudflareTunnelsEnabled: true })} />);
        expect(screen.getByText(/you will need to update the server's IP address back to its real/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Disable Cloudflare Tunnel' })).toBeInTheDocument();
        expect(screen.queryByText('Automated')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Cloudflare Token')).not.toBeInTheDocument();
    });

    it('does not disable the tunnel when the prompt confirmation text does not match', () => {
        render(<CloudflareTunnel {...baseProps({ isCloudflareTunnelsEnabled: true })} />);
        vi.spyOn(window, 'prompt').mockReturnValue('nope');

        act(() => screen.getByRole('button', { name: 'Disable Cloudflare Tunnel' }).click());

        expect(routerPostSpy).not.toHaveBeenCalled();
    });

    it('disables the tunnel via router.post(toggleUrl) when the prompt confirmation matches exactly', () => {
        render(<CloudflareTunnel {...baseProps({ isCloudflareTunnelsEnabled: true })} />);
        vi.spyOn(window, 'prompt').mockReturnValue('DISABLE CLOUDFLARE TUNNEL');

        act(() => screen.getByRole('button', { name: 'Disable Cloudflare Tunnel' }).click());

        expect(routerPostSpy).toHaveBeenCalledWith(
            '/server/srv-uuid/cloudflare-tunnel/toggle',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('shows the not-functional info box only when disabled and not functional, hides the automated section', () => {
        render(<CloudflareTunnel {...baseProps({ isCloudflareTunnelsEnabled: false, isFunctional: false })} />);
        expect(screen.getByText(/please validate your server first/)).toBeInTheDocument();
        expect(screen.queryByText('Automated')).not.toBeInTheDocument();
    });

    it('shows the automated-config form when disabled and functional, with no info box', () => {
        render(<CloudflareTunnel {...baseProps({ isCloudflareTunnelsEnabled: false, isFunctional: true })} />);
        expect(screen.getByText('Automated')).toBeInTheDocument();
        expect(screen.getByLabelText('Cloudflare Token')).toBeInTheDocument();
        expect(screen.getByLabelText('Configured SSH Domain')).toBeInTheDocument();
        expect(screen.queryByText(/please validate your server first/)).not.toBeInTheDocument();
    });

    it('shows a permission-denied message instead of the automated form when canUpdate is false', () => {
        render(<CloudflareTunnel {...baseProps({ isCloudflareTunnelsEnabled: false, isFunctional: true, canUpdate: false })} />);
        expect(screen.getByText('Automated')).toBeInTheDocument();
        expect(screen.queryByLabelText('Cloudflare Token')).not.toBeInTheDocument();
        expect(screen.getAllByText("You don't have permission to configure Cloudflare Tunnel for this server.")).toHaveLength(2);
    });

    it('submits the automated-config form via post(automatedConfigUrl, {preserveScroll: true})', () => {
        render(<CloudflareTunnel {...baseProps()} />);
        act(() => typeInto(screen.getByLabelText('Cloudflare Token'), 'fake-tunnel-token'));
        act(() => typeInto(screen.getByLabelText('Configured SSH Domain'), 'ssh.example.com'));
        act(() => screen.getByRole('button', { name: 'Continue' }).click());

        expect(postSpy).toHaveBeenCalledWith(
            '/server/srv-uuid/cloudflare-tunnel/automated-config',
            { cloudflare_token: 'fake-tunnel-token', ssh_domain: 'ssh.example.com' },
            { preserveScroll: true },
        );
    });

    it('renders per-field errors for cloudflare_token and ssh_domain', () => {
        mockErrors = { cloudflare_token: 'The cloudflare token field is required.', ssh_domain: 'The ssh domain field is required.' };
        render(<CloudflareTunnel {...baseProps()} />);

        expect(screen.getByText('The cloudflare token field is required.')).toBeInTheDocument();
        expect(screen.getByText('The ssh domain field is required.')).toBeInTheDocument();
    });

    it('disables the Continue button while processing', () => {
        mockProcessing = true;
        render(<CloudflareTunnel {...baseProps()} />);
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
    });

    it('always renders the Manual section regardless of enabled/functional state', () => {
        const { rerender } = render(<CloudflareTunnel {...baseProps({ isCloudflareTunnelsEnabled: true })} />);
        expect(screen.getByRole('button', { name: 'I manually configured Cloudflare Tunnel' })).toBeInTheDocument();

        rerender(<CloudflareTunnel {...baseProps({ isCloudflareTunnelsEnabled: false, isFunctional: false })} />);
        expect(screen.getByRole('button', { name: 'I manually configured Cloudflare Tunnel' })).toBeInTheDocument();
    });

    it('shows a permission-denied message instead of the manual button when canUpdate is false', () => {
        render(<CloudflareTunnel {...baseProps({ canUpdate: false })} />);
        expect(screen.queryByRole('button', { name: 'I manually configured Cloudflare Tunnel' })).not.toBeInTheDocument();
    });

    it('does not confirm manual config when the prompt confirmation text does not match', () => {
        render(<CloudflareTunnel {...baseProps()} />);
        vi.spyOn(window, 'prompt').mockReturnValue('nope');

        act(() => screen.getByRole('button', { name: 'I manually configured Cloudflare Tunnel' }).click());

        expect(routerPostSpy).not.toHaveBeenCalled();
    });

    it('confirms manual config via router.post(manualConfigUrl) when the prompt confirmation matches exactly', () => {
        render(<CloudflareTunnel {...baseProps()} />);
        vi.spyOn(window, 'prompt').mockReturnValue('I manually configured Cloudflare Tunnel');

        act(() => screen.getByRole('button', { name: 'I manually configured Cloudflare Tunnel' }).click());

        expect(routerPostSpy).toHaveBeenCalledWith(
            '/server/srv-uuid/cloudflare-tunnel/manual-config',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('opens the log modal when flash carries a cloudflare-tunnel activity context', () => {
        mockFlash = { activityContext: 'cloudflare-tunnel', activityId: 'act-77' };
        render(<CloudflareTunnel {...baseProps()} />);

        expect(screen.getByText('Cloudflare Tunnel Configuration')).toBeInTheDocument();
        expect(screen.getByTestId('activity-log')).toHaveTextContent('Logs - act-77');
    });

    it('does not open the log modal for an unrelated flash activity context', () => {
        mockFlash = { activityContext: 'patches-update', activityId: 'act-77' };
        render(<CloudflareTunnel {...baseProps()} />);

        expect(screen.queryByText('Cloudflare Tunnel Configuration')).not.toBeInTheDocument();
    });

    it('closes the log modal via the ✕ button', () => {
        mockFlash = { activityContext: 'cloudflare-tunnel', activityId: 'act-77' };
        render(<CloudflareTunnel {...baseProps()} />);

        act(() => screen.getByRole('button', { name: '✕' }).click());
        expect(screen.queryByText('Cloudflare Tunnel Configuration')).not.toBeInTheDocument();
    });

    it('closes the log modal via the backdrop click', () => {
        mockFlash = { activityContext: 'cloudflare-tunnel', activityId: 'act-77' };
        render(<CloudflareTunnel {...baseProps()} />);

        const backdrop = document.querySelector('.absolute.inset-0.h-full.w-full');
        act(() => backdrop.click());
        expect(screen.queryByText('Cloudflare Tunnel Configuration')).not.toBeInTheDocument();
    });
});
