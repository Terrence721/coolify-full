import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SettingsOauth from './SettingsOauth';

// Real logic: an immutable by-index array update (updateProvider) shared by every provider row -
// easy to get subtly wrong (updating the wrong row, or mutating in place), plus two independent
// provider-membership-driven conditional fields (Tenant for azure/google, Base URL for
// authentik/clerk/zitadel/gitlab) that most providers show neither of. Previously untested.

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

function provider(overrides = {}) {
    return {
        id: 1,
        provider: 'github',
        enabled: false,
        client_id: '',
        client_secret: '',
        redirect_uri: '',
        tenant: '',
        base_url: '',
        callbackUrl: 'https://coolify.test/auth/github/callback',
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

describe('SettingsOauth - per-provider conditional fields', () => {
    it('shows neither Tenant nor Base URL for a plain provider like github', () => {
        render(<SettingsOauth providers={[provider({ provider: 'github' })]} updateUrl="/settings/oauth" />);

        expect(screen.queryByLabelText('Tenant')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Base URL')).not.toBeInTheDocument();
    });

    it('shows Tenant but not Base URL for azure', () => {
        render(<SettingsOauth providers={[provider({ provider: 'azure' })]} updateUrl="/settings/oauth" />);

        expect(screen.getByLabelText('Tenant')).toBeInTheDocument();
        expect(screen.queryByLabelText('Base URL')).not.toBeInTheDocument();
    });

    it('shows Tenant but not Base URL for google', () => {
        render(<SettingsOauth providers={[provider({ provider: 'google' })]} updateUrl="/settings/oauth" />);

        expect(screen.getByLabelText('Tenant')).toBeInTheDocument();
        expect(screen.queryByLabelText('Base URL')).not.toBeInTheDocument();
    });

    it('shows Base URL but not Tenant for authentik, clerk, zitadel, and gitlab', () => {
        for (const name of ['authentik', 'clerk', 'zitadel', 'gitlab']) {
            const { unmount } = render(<SettingsOauth providers={[provider({ provider: name })]} updateUrl="/settings/oauth" />);
            expect(screen.getByLabelText('Base URL')).toBeInTheDocument();
            expect(screen.queryByLabelText('Tenant')).not.toBeInTheDocument();
            unmount();
        }
    });

    it('uses the redirect_uri field as a placeholder fallback from callbackUrl', () => {
        render(<SettingsOauth providers={[provider({ redirect_uri: '' })]} updateUrl="/settings/oauth" />);

        expect(screen.getByLabelText('Redirect URI')).toHaveAttribute('placeholder', 'https://coolify.test/auth/github/callback');
    });
});

describe('SettingsOauth - updateProvider by-index update', () => {
    it('updates only the targeted provider row, leaving sibling rows untouched', () => {
        render(
            <SettingsOauth
                providers={[provider({ id: 1, provider: 'github', client_id: 'gh-id' }), provider({ id: 2, provider: 'gitlab', client_id: 'gl-id' })]}
                updateUrl="/settings/oauth"
            />,
        );

        const inputs = screen.getAllByLabelText('Client ID');
        fireEvent.change(inputs[1], { target: { value: 'gl-id-changed' } });

        const updatedInputs = screen.getAllByLabelText('Client ID');
        expect(updatedInputs[0]).toHaveValue('gh-id');
        expect(updatedInputs[1]).toHaveValue('gl-id-changed');
    });

    it('toggles a single provider Enabled checkbox without affecting others', () => {
        render(
            <SettingsOauth
                providers={[provider({ id: 1, provider: 'github', enabled: false }), provider({ id: 2, provider: 'gitlab', enabled: false })]}
                updateUrl="/settings/oauth"
            />,
        );

        const checkboxes = screen.getAllByLabelText('Enabled');
        fireEvent.click(checkboxes[0]);

        expect(checkboxes[0]).toBeChecked();
        expect(checkboxes[1]).not.toBeChecked();
    });
});

describe('SettingsOauth - errors and submit', () => {
    it('renders a field-level error keyed to the provider index', () => {
        formErrors = { 'providers.0.client_id': 'Client ID is required when enabled.' };
        render(<SettingsOauth providers={[provider()]} updateUrl="/settings/oauth" />);

        expect(screen.getByText('Client ID is required when enabled.')).toBeInTheDocument();
    });

    it('submits the whole providers array to updateUrl', () => {
        render(<SettingsOauth providers={[provider()]} updateUrl="/settings/oauth" />);

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(putSpy).toHaveBeenCalledWith('/settings/oauth', undefined);
    });
});
