import { render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Change from './Change';

// Regression coverage for a real bug found during the 2026-07-24 `/source/github/{uuid}` smoke
// test (issue #25): Manual Installation (createManual()) persists placeholder app_id/
// installation_id (1234567890) server-side and redirects back to this same route/component - an
// Inertia soft navigation that reuses the existing component instance rather than remounting it.
// useForm()'s initial data snapshot is captured once at mount from the (then-null) appId/
// installationId, so the newly-set placeholder values never reached the App Id/Installation Id
// fields - confirmed live via a real network trace: the server's fresh Inertia props correctly
// contained 1234567890 for both, but the rendered inputs stayed blank; a hard reload of the same
// URL showed the correct values immediately. Fixed with a useEffect that resyncs the whole form
// from githubApp whenever the prop changes.

vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (keyOrData, value) => {
                if (typeof keyOrData === 'string') {
                    setDataState((prev) => ({ ...prev, [keyOrData]: value }));
                } else if (typeof keyOrData === 'function') {
                    setDataState(keyOrData);
                } else {
                    setDataState(keyOrData);
                }
            },
            put: vi.fn(),
            processing: false,
        };
    },
}));

function githubApp(overrides = {}) {
    return {
        uuid: 'gh-app-1',
        name: 'throwaway-smoketest-app',
        organization: null,
        apiUrl: 'https://api.github.com',
        htmlUrl: 'https://github.com',
        customUser: 'git',
        customPort: 22,
        appId: null,
        installationId: null,
        clientId: null,
        clientSecret: null,
        webhookSecret: null,
        isSystemWide: false,
        privateKeyId: null,
        contents: null,
        metadata: null,
        pullRequests: null,
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        githubApp: githubApp(),
        activeTab: 'general',
        isCloud: false,
        isDev: true,
        fqdn: null,
        ipv4: null,
        ipv6: null,
        appUrl: 'http://localhost',
        webhookEndpoint: 'http://localhost',
        manifestState: 'state-token',
        devWebhookUrl: null,
        privateKeys: [{ id: 1, name: 'Default Key' }],
        applications: [],
        canUpdate: true,
        canDelete: true,
        canCreate: true,
        installationPath: '/install',
        permissionsPath: '/permissions',
        nameUpdatePath: 'https://github.com/settings/apps/throwaway-smoketest-app',
        showUrl: '/source/github/gh-app-1',
        permissionsUrl: '/source/github/gh-app-1/permissions',
        resourcesUrl: '/source/github/gh-app-1/resources',
        updateUrl: '/source/github/gh-app-1',
        updateNameUrl: '/source/github/gh-app-1/update-name',
        checkPermissionsUrl: '/source/github/gh-app-1/check-permissions',
        instantSaveUrl: '/source/github/gh-app-1/instant-save',
        createManualUrl: '/source/github/gh-app-1/create-manual',
        deleteUrl: '/source/github/gh-app-1',
        ...overrides,
    };
}

describe('Source/Github/Change', () => {
    it('shows the Register Now / Manual Installation choices before an appId is set', () => {
        render(<Change {...baseProps({ githubApp: githubApp({ appId: null }) })} />);
        expect(screen.getByRole('button', { name: 'Register Now' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Continue' })).toBeInTheDocument();
        expect(screen.queryByLabelText('App Id')).not.toBeInTheDocument();
    });

    it('shows the "must complete this step" callout once appId is set but installationId is not', () => {
        render(<Change {...baseProps({ githubApp: githubApp({ appId: 1234567890, installationId: null }) })} />);
        expect(screen.getByText('You must complete this step before you can use this source!')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Install Repositories on GitHub' })).toBeInTheDocument();
    });

    it('renders the real appId/installationId in the tabbed General config once both are set', () => {
        render(<Change {...baseProps({ githubApp: githubApp({ appId: 1234567890, installationId: 37267016 }) })} />);
        expect(screen.getByLabelText('App Id')).toHaveValue(1234567890);
        expect(screen.getByLabelText('Installation Id')).toHaveValue(37267016);
    });

    it('resyncs App Id/Installation Id after an Inertia soft navigation reuses this same mounted component (Manual Installation)', () => {
        const { rerender } = render(<Change {...baseProps({ githubApp: githubApp({ appId: null, installationId: null }) })} />);
        expect(screen.getByRole('button', { name: 'Register Now' })).toBeInTheDocument();

        // Simulates createManual()'s router.post() -> redirect back to this same route/component:
        // Inertia reuses the mounted component and only the props change, exactly like `rerender`.
        rerender(<Change {...baseProps({ githubApp: githubApp({ appId: 1234567890, installationId: 1234567890 }) })} />);

        expect(screen.getByLabelText('App Id')).toHaveValue(1234567890);
        expect(screen.getByLabelText('Installation Id')).toHaveValue(1234567890);
    });
});
