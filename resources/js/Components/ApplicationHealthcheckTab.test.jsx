import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ApplicationHealthcheckTab from './ApplicationHealthcheckTab';

// The application healthcheck tab (280 lines) - the http/cmd type switch swaps out an entire
// field group AND changes what shape gets submitted, "Enable" is gated behind a confirm modal
// (with the exact same accessible name as the toolbar button once open) while "Disable" fires
// immediately, and a customHealthcheckFound banner is conditional. Genuinely more branching than
// its sibling DatabaseHealthcheckTab, which has no type switch at all.

const routerPatch = vi.fn();
const routerPost = vi.fn();
vi.mock('@inertiajs/react', () => ({
    router: {
        patch: (url, data, opts) => routerPatch(url, data, opts),
        post: (url, data, opts) => routerPost(url, data, opts),
    },
}));

afterEach(() => {
    routerPatch.mockClear();
    routerPost.mockClear();
});

function baseHealthcheck(overrides = {}) {
    return {
        healthCheckEnabled: true,
        healthCheckType: 'http',
        healthCheckCommand: '',
        healthCheckMethod: 'GET',
        healthCheckScheme: 'http',
        healthCheckHost: 'localhost',
        healthCheckPort: '80',
        healthCheckPath: '/health',
        healthCheckReturnCode: '200',
        healthCheckResponseText: '',
        healthCheckInterval: 30,
        healthCheckTimeout: 30,
        healthCheckRetries: 3,
        healthCheckStartPeriod: 30,
        customHealthcheckFound: false,
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        healthcheck: baseHealthcheck(),
        healthcheckUrls: { update: '/healthcheck/update', toggle: '/healthcheck/toggle' },
        canUpdate: true,
        ...overrides,
    };
}

describe('ApplicationHealthcheckTab', () => {
    it('shows the HTTP fields and hides the Command field when healthCheckType is http', () => {
        render(<ApplicationHealthcheckTab {...baseProps()} />);
        expect(screen.getByLabelText('Method')).toBeInTheDocument();
        expect(screen.getByLabelText('Host')).toBeInTheDocument();
        expect(screen.queryByLabelText('Command')).not.toBeInTheDocument();
    });

    it('shows the Command field and shell-operator warning, and hides the HTTP fields, when healthCheckType is cmd', () => {
        render(<ApplicationHealthcheckTab {...baseProps({ healthcheck: baseHealthcheck({ healthCheckType: 'cmd' }) })} />);
        expect(screen.getByLabelText('Command')).toBeInTheDocument();
        expect(screen.getByText(/Shell operators/)).toBeInTheDocument();
        expect(screen.queryByLabelText('Method')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Host')).not.toBeInTheDocument();
    });

    it('switches the rendered field group when the Type select changes', () => {
        render(<ApplicationHealthcheckTab {...baseProps()} />);
        expect(screen.getByLabelText('Host')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'cmd' } });

        expect(screen.queryByLabelText('Host')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Command')).toBeInTheDocument();
    });

    it('shows the custom-healthcheck-detected warning only when customHealthcheckFound is true', () => {
        const { rerender } = render(
            <ApplicationHealthcheckTab {...baseProps({ healthcheck: baseHealthcheck({ customHealthcheckFound: false }) })} />,
        );
        expect(screen.queryByText(/custom health check has been detected/)).not.toBeInTheDocument();

        rerender(<ApplicationHealthcheckTab {...baseProps({ healthcheck: baseHealthcheck({ customHealthcheckFound: true }) })} />);
        expect(screen.getByText(/custom health check has been detected/)).toBeInTheDocument();
    });

    it('hides Save/Enable/Disable buttons but keeps fields visible (disabled) when canUpdate is false', () => {
        render(<ApplicationHealthcheckTab {...baseProps({ canUpdate: false })} />);
        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Disable Healthcheck' })).not.toBeInTheDocument();
        expect(screen.getByLabelText('Host')).toBeDisabled();
    });

    it('shows a Disable Healthcheck button (no confirm) when enabled, and an Enable button (confirm-gated) when disabled', () => {
        const { unmount } = render(<ApplicationHealthcheckTab {...baseProps({ healthcheck: baseHealthcheck({ healthCheckEnabled: true }) })} />);
        expect(screen.getByRole('button', { name: 'Disable Healthcheck' })).toBeInTheDocument();
        unmount();

        render(<ApplicationHealthcheckTab {...baseProps({ healthcheck: baseHealthcheck({ healthCheckEnabled: false }) })} />);
        expect(screen.getByRole('button', { name: 'Enable Healthcheck' })).toBeInTheDocument();
    });

    it('disabling fires router.post(toggle) immediately, with no confirm modal', () => {
        render(<ApplicationHealthcheckTab {...baseProps({ healthcheck: baseHealthcheck({ healthCheckEnabled: true }) })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Disable Healthcheck' }));

        expect(routerPost).toHaveBeenCalledWith('/healthcheck/toggle', {}, { preserveScroll: true });
    });

    it('enabling opens a confirm modal instead of calling toggle immediately', () => {
        render(<ApplicationHealthcheckTab {...baseProps({ healthcheck: baseHealthcheck({ healthCheckEnabled: false }) })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Enable Healthcheck' }));

        expect(routerPost).not.toHaveBeenCalled();
        expect(screen.getByText('Confirm Healthcheck Enable?')).toBeInTheDocument();
    });

    it('confirming inside the modal calls router.post(toggle) and closes the modal', () => {
        render(<ApplicationHealthcheckTab {...baseProps({ healthcheck: baseHealthcheck({ healthCheckEnabled: false }) })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Enable Healthcheck' }));

        const modal = screen.getByText('Confirm Healthcheck Enable?').closest('div').parentElement;
        fireEvent.click(within(modal).getByRole('button', { name: 'Enable Healthcheck' }));

        expect(routerPost).toHaveBeenCalledWith('/healthcheck/toggle', {}, { preserveScroll: true });
        expect(screen.queryByText('Confirm Healthcheck Enable?')).not.toBeInTheDocument();
    });

    it('cancelling the modal closes it without calling toggle', () => {
        render(<ApplicationHealthcheckTab {...baseProps({ healthcheck: baseHealthcheck({ healthCheckEnabled: false }) })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Enable Healthcheck' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(routerPost).not.toHaveBeenCalled();
        expect(screen.queryByText('Confirm Healthcheck Enable?')).not.toBeInTheDocument();
    });

    it('submits the edited form state and echoes healthCheckEnabled/customHealthcheckFound from props, not form state', () => {
        render(
            <ApplicationHealthcheckTab
                {...baseProps({ healthcheck: baseHealthcheck({ healthCheckEnabled: true, customHealthcheckFound: true }) })}
            />,
        );
        fireEvent.change(screen.getByLabelText('Host'), { target: { value: 'api.internal' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(routerPatch).toHaveBeenCalledWith(
            '/healthcheck/update',
            expect.objectContaining({
                healthCheckHost: 'api.internal',
                healthCheckEnabled: true,
                customHealthcheckFound: true,
            }),
            { preserveScroll: true },
        );
    });
});
