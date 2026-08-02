import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Advanced from './Advanced';

// Real logic here: two security-relevant settings (public registration, two-step confirmation)
// use an asymmetric gate - turning the protection OFF requires a typed PasswordConfirmModal
// confirmation, while restoring it is a plain instant checkbox - plus a derived warning banner
// that parses the comma-separated allowed_ips string for an open '0.0.0.0' entry. Previously
// untested.

const putSpy = vi.fn();
let formErrors = {};

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            put: (url, options) => putSpy(url, options),
            processing: false,
            errors: formErrors,
        };
    },
}));

vi.mock('../../Components/PasswordConfirmModal', () => ({
    default: ({ title, action, confirmationText, onClose, onDone }) => (
        <div data-testid="password-confirm-modal">
            <span>{title}</span>
            <span>{action.url}</span>
            <span>{action.method}</span>
            <span>{confirmationText}</span>
            <button type="button" onClick={onDone}>
                Confirm
            </button>
            <button type="button" onClick={onClose}>
                Cancel
            </button>
        </div>
    ),
}));

function baseSettings(overrides = {}) {
    return {
        is_registration_enabled: false,
        do_not_track: false,
        is_dns_validation_enabled: true,
        custom_dns_servers: '',
        is_api_enabled: true,
        allowed_ips: '1.2.3.4',
        disable_two_step_confirmation: false,
        is_wire_navigate_enabled: true,
        is_mcp_server_enabled: false,
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        settings: baseSettings(overrides.settings),
        mcpUrl: 'https://coolify.test/mcp',
        updateUrl: '/settings/advanced',
        enableRegistrationUrl: '/settings/advanced/enable-registration',
        disableTwoStepConfirmationUrl: '/settings/advanced/disable-two-step-confirmation',
        ...overrides,
    };
}

beforeEach(() => {
    formErrors = {};
});

afterEach(() => {
    putSpy.mockClear();
    vi.restoreAllMocks();
});

describe('Settings/Advanced - registration gate', () => {
    it('shows an Enable button, not a checkbox, when registration is currently disabled', () => {
        render(<Advanced {...baseProps({ settings: { is_registration_enabled: false } })} />);

        expect(screen.getByRole('button', { name: 'Enable' })).toBeInTheDocument();
        expect(screen.queryByLabelText('Registration Allowed')).not.toBeInTheDocument();
    });

    it('opens a PasswordConfirmModal targeting enableRegistrationUrl when Enable is clicked', () => {
        render(<Advanced {...baseProps({ settings: { is_registration_enabled: false } })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Enable' }));

        const modal = screen.getByTestId('password-confirm-modal');
        expect(modal).toHaveTextContent('Confirm Enabling Registration?');
        expect(modal).toHaveTextContent('/settings/advanced/enable-registration');
        expect(modal).toHaveTextContent('post');
        expect(modal).toHaveTextContent('ENABLE REGISTRATION');
    });

    it('closes the modal without changing local state when Cancel is clicked', () => {
        render(<Advanced {...baseProps({ settings: { is_registration_enabled: false } })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Enable' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(screen.queryByTestId('password-confirm-modal')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Enable' })).toBeInTheDocument();
    });

    it('closes the modal when the confirm action reports done', () => {
        render(<Advanced {...baseProps({ settings: { is_registration_enabled: false } })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Enable' }));
        fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));

        expect(screen.queryByTestId('password-confirm-modal')).not.toBeInTheDocument();
    });

    it('shows a plain, directly-togglable checkbox once registration is already enabled', () => {
        render(<Advanced {...baseProps({ settings: { is_registration_enabled: true } })} />);

        expect(screen.queryByRole('button', { name: 'Enable' })).not.toBeInTheDocument();
        const checkbox = screen.getByLabelText('Registration Allowed');
        expect(checkbox).toBeChecked();

        fireEvent.click(checkbox);
        expect(checkbox).not.toBeChecked();
    });
});

describe('Settings/Advanced - two-step confirmation gate', () => {
    it('shows a Disable button and the reduced-security warning while still enabled', () => {
        render(<Advanced {...baseProps({ settings: { disable_two_step_confirmation: false } })} />);

        expect(screen.getByRole('button', { name: 'Disable' })).toBeInTheDocument();
        expect(screen.getByText(/reduces security/)).toBeInTheDocument();
        expect(screen.queryByLabelText('Disable Two Step Confirmation')).not.toBeInTheDocument();
    });

    it('opens a PasswordConfirmModal targeting disableTwoStepConfirmationUrl when Disable is clicked', () => {
        render(<Advanced {...baseProps({ settings: { disable_two_step_confirmation: false } })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Disable' }));

        const modal = screen.getByTestId('password-confirm-modal');
        expect(modal).toHaveTextContent('Confirm Disabling Two Step Confirmation?');
        expect(modal).toHaveTextContent('/settings/advanced/disable-two-step-confirmation');
        expect(modal).toHaveTextContent('DISABLE TWO STEP CONFIRMATION');
    });

    it('shows a plain checkbox and no warning once two-step confirmation is already disabled', () => {
        render(<Advanced {...baseProps({ settings: { disable_two_step_confirmation: true } })} />);

        expect(screen.queryByRole('button', { name: 'Disable' })).not.toBeInTheDocument();
        expect(screen.queryByText(/reduces security/)).not.toBeInTheDocument();
        const checkbox = screen.getByLabelText('Disable Two Step Confirmation');
        expect(checkbox).toBeChecked();

        fireEvent.click(checkbox);
        expect(checkbox).not.toBeChecked();
    });
});

describe('Settings/Advanced - allowed IPs warning', () => {
    it('warns when allowed_ips is empty', () => {
        render(<Advanced {...baseProps({ settings: { allowed_ips: '' } })} />);
        expect(screen.getByText(/allows API access from anywhere/)).toBeInTheDocument();
    });

    it('warns when 0.0.0.0 is one of several comma-separated entries, even with surrounding spaces', () => {
        render(<Advanced {...baseProps({ settings: { allowed_ips: '10.0.0.0/8, 0.0.0.0 ,203.0.113.0/24' } })} />);
        expect(screen.getByText(/allows API access from anywhere/)).toBeInTheDocument();
    });

    it('does not warn for a real, non-open allowlist', () => {
        render(<Advanced {...baseProps({ settings: { allowed_ips: '10.0.0.0/8,203.0.113.0/24' } })} />);
        expect(screen.queryByText(/allows API access from anywhere/)).not.toBeInTheDocument();
    });

    it('renders a field-level error for allowed_ips', () => {
        formErrors = { allowed_ips: 'Invalid IP or CIDR range.' };
        render(<Advanced {...baseProps()} />);
        expect(screen.getByText('Invalid IP or CIDR range.')).toBeInTheDocument();
    });
});

describe('Settings/Advanced - MCP server panel', () => {
    it('hides the endpoint panel while the MCP server is disabled', () => {
        render(<Advanced {...baseProps({ settings: { is_mcp_server_enabled: false } })} />);
        expect(screen.queryByText('https://coolify.test/mcp')).not.toBeInTheDocument();
    });

    it('shows the endpoint panel once the MCP server checkbox is enabled', () => {
        render(<Advanced {...baseProps({ settings: { is_mcp_server_enabled: false } })} />);

        fireEvent.click(screen.getByLabelText('Enable MCP Server'));

        expect(screen.getByText('https://coolify.test/mcp')).toBeInTheDocument();
    });
});

describe('Settings/Advanced - remaining fields and submit', () => {
    it('renders a field-level error for custom_dns_servers', () => {
        formErrors = { custom_dns_servers: 'Invalid DNS server.' };
        render(<Advanced {...baseProps()} />);
        expect(screen.getByText('Invalid DNS server.')).toBeInTheDocument();
    });

    it('toggles Do Not Track, DNS Validation, API Access, and SPA Navigation independently', () => {
        render(<Advanced {...baseProps()} />);

        const doNotTrack = screen.getByLabelText('Do Not Track');
        const dnsValidation = screen.getByLabelText('DNS Validation');
        const apiAccess = screen.getByLabelText('API Access');
        const spaNavigation = screen.getByLabelText('SPA Navigation');

        expect(doNotTrack).not.toBeChecked();
        expect(dnsValidation).toBeChecked();
        expect(apiAccess).toBeChecked();
        expect(spaNavigation).toBeChecked();

        fireEvent.click(doNotTrack);
        expect(doNotTrack).toBeChecked();
        expect(dnsValidation).toBeChecked();
    });

    it('submits the whole form to updateUrl', () => {
        render(<Advanced {...baseProps()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(putSpy).toHaveBeenCalledWith('/settings/advanced', undefined);
    });
});
