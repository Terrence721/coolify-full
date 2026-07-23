import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ApplicationGeneralTab from './ApplicationGeneralTab';

// Covers the build-pack-driven field visibility (static/SPA toggles hidden for a Dockerfile
// override or Docker Image build pack, Static Image selector only for static sites), the
// instant-save checkboxes (static/SPA/HTTP Basic Auth toggle immediately via router.patch, not
// just local form state, unlike most fields here which wait for the main Save button), the
// confusingly-named isContainerLabelReadonlyEnabled flag (true means the Domains field is
// *editable*, false means it's read-only - inverted from what the name suggests), the
// Docker Registry section hidden entirely for Compose apps, and the compose-service-domains
// list filtering out database images - none of it previously tested.

const patchSpy = vi.fn();
const postSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        patch: (url, data, options) => patchSpy(url, data, options),
        post: (url, data, options) => postSpy(url, data, options),
        reload: () => {},
    },
    usePage: () => ({ props: {} }),
}));

function baseGeneral(overrides = {}) {
    return {
        name: 'storefront-web',
        description: '',
        fqdn: 'https://taken.example.com',
        gitRepository: 'acme-corp/storefront-web',
        gitBranch: 'main',
        buildPack: 'nixpacks',
        couldSetBuildCommands: true,
        isStatic: false,
        isSpa: false,
        isHttpBasicAuthEnabled: false,
        isContainerLabelReadonlyEnabled: false,
        isSwarm: false,
        dockerfile: '',
        composeServices: [],
        isRawComposeDeploymentEnabled: false,
        dockerComposeRaw: '',
        redirect: 'both',
        ...overrides,
    };
}

function baseProps({ general: generalOverrides, ...overrides } = {}) {
    return {
        general: baseGeneral(generalOverrides),
        resourceDetails: {},
        generalUrls: {
            update: '/app/1',
            instantSave: '/app/1/instant',
            loadCompose: '/app/1/load-compose',
            generateServiceDomain: '/app/1/service-domain',
            wildcardDomain: '/app/1/wildcard-domain',
            generateNginxConfig: '/app/1/nginx-config',
        },
        canUpdate: true,
        ...overrides,
    };
}

beforeEach(() => {
    patchSpy.mockClear();
    postSpy.mockClear();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('ApplicationGeneralTab', () => {
    it('hides the static/SPA toggles for a Docker Image build pack', () => {
        render(<ApplicationGeneralTab {...baseProps({ general: { buildPack: 'dockerimage' } })} />);

        expect(screen.queryByLabelText('Is it a static site?')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Is it a SPA (Single Page Application)?')).not.toBeInTheDocument();
    });

    it('hides the static toggle when there is a Dockerfile override, even for an otherwise-eligible build pack', () => {
        render(<ApplicationGeneralTab {...baseProps({ general: { dockerfile: 'FROM nginx' } })} />);

        expect(screen.queryByLabelText('Is it a static site?')).not.toBeInTheDocument();
    });

    it('only shows the SPA toggle once Is it a static site? is checked, and not for the static build pack itself', () => {
        render(<ApplicationGeneralTab {...baseProps({ general: { isStatic: false } })} />);
        expect(screen.queryByLabelText('Is it a SPA (Single Page Application)?')).not.toBeInTheDocument();

        render(<ApplicationGeneralTab {...baseProps({ general: { isStatic: true, buildPack: 'nixpacks' } })} />);
        expect(screen.getByLabelText('Is it a SPA (Single Page Application)?')).toBeInTheDocument();
    });

    it('hides the SPA toggle for the static build pack itself, even with isStatic true', () => {
        render(<ApplicationGeneralTab {...baseProps({ general: { isStatic: true, buildPack: 'static' } })} />);
        expect(screen.queryByLabelText('Is it a SPA (Single Page Application)?')).not.toBeInTheDocument();
    });

    it('only shows the Static Image selector for a static site or the static build pack', () => {
        render(<ApplicationGeneralTab {...baseProps({ general: { isStatic: false, buildPack: 'nixpacks' } })} />);
        expect(screen.queryByLabelText('Static Image')).not.toBeInTheDocument();

        render(<ApplicationGeneralTab {...baseProps({ general: { isStatic: true, buildPack: 'nixpacks' } })} />);
        expect(screen.getByLabelText('Static Image')).toBeInTheDocument();
    });

    it('checking the static-site toggle instant-saves via router.patch immediately, not just local state', () => {
        render(<ApplicationGeneralTab {...baseProps()} />);

        act(() => screen.getByLabelText('Is it a static site?').click());

        expect(patchSpy).toHaveBeenCalledWith('/app/1/instant', expect.objectContaining({ isStatic: true }), expect.any(Object));
    });

    it('treats isContainerLabelReadonlyEnabled=false as "Domains is read-only" and true as "Domains is editable"', () => {
        const { rerender } = render(<ApplicationGeneralTab {...baseProps({ general: { isContainerLabelReadonlyEnabled: false } })} />);
        expect(screen.getByLabelText('Domains')).toHaveAttribute('readonly');

        rerender(<ApplicationGeneralTab {...baseProps({ general: { isContainerLabelReadonlyEnabled: true } })} />);
        expect(screen.getByLabelText('Domains')).not.toHaveAttribute('readonly');
    });

    it('reveals the HTTP Basic Auth username/password fields only once Enable is checked, and instant-saves the toggle', () => {
        render(<ApplicationGeneralTab {...baseProps()} />);

        expect(screen.queryByLabelText('Username')).not.toBeInTheDocument();

        act(() => screen.getByLabelText('Enable').click());

        expect(patchSpy).toHaveBeenCalledWith('/app/1/instant', expect.objectContaining({ isHttpBasicAuthEnabled: true }), expect.any(Object));
    });

    it('hides the Docker Registry section entirely for a Docker Compose build pack', () => {
        render(<ApplicationGeneralTab {...baseProps({ general: { buildPack: 'dockercompose' } })} />);

        expect(screen.queryByText('Docker Registry')).not.toBeInTheDocument();
    });

    it('lists compose-service domain fields, excluding any service flagged as a database image', () => {
        render(
            <ApplicationGeneralTab
                {...baseProps({
                    general: {
                        buildPack: 'dockercompose',
                        composeServices: [
                            { sanitizedKey: 'web', name: 'web', isDatabaseImage: false },
                            { sanitizedKey: 'db', name: 'db', isDatabaseImage: true },
                        ],
                    },
                })}
            />,
        );

        expect(screen.getByLabelText('Domains for web')).toBeInTheDocument();
        expect(screen.queryByLabelText('Domains for db')).not.toBeInTheDocument();
    });

    it('submits the main form via patch to generalUrls.update', () => {
        render(<ApplicationGeneralTab {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'Save' }).click());

        expect(patchSpy).toHaveBeenCalledWith('/app/1', expect.any(Object), expect.any(Object));
    });

    it('auto-loads the compose file on mount for a Compose app with no compose file yet', () => {
        render(<ApplicationGeneralTab {...baseProps({ general: { buildPack: 'dockercompose', dockerComposeRaw: '' } })} />);

        expect(postSpy).toHaveBeenCalledWith('/app/1/load-compose', { isInit: true }, expect.any(Object));
    });

    it('does not auto-load the compose file when one already exists', () => {
        render(<ApplicationGeneralTab {...baseProps({ general: { buildPack: 'dockercompose', dockerComposeRaw: 'services: {}' } })} />);

        expect(postSpy).not.toHaveBeenCalled();
    });
});
