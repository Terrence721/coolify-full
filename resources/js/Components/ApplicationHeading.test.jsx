import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ApplicationHeading from './ApplicationHeading';

// Covers the exited-vs-running button set (only "Deploy" when exited; Redeploy/Restart/Stop/
// Force-deploy otherwise - manually verified live end-to-end during the 2026-07-23 Application
// Deployment smoke test, this locks that behavior in), the Swarm variant (Redeploy becomes
// "Update Service", Force deploy hidden), the Docker Compose "Please load a Compose file"
// gate, the Terminal link's canAccessTerminal + !isSwarm guard, and the Stop button's
// window.confirm() gate - none of it previously tested.

const postSpy = vi.fn();
let mockPermissions = { canAccessTerminal: true };

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => postSpy(url, data, options),
        reload: () => {},
    },
    usePage: () => ({ props: { permissions: mockPermissions } }),
}));

vi.mock('../hooks/useTeamChannel', () => ({
    useTeamChannel: () => {},
}));

function baseApplication(overrides = {}) {
    return {
        name: 'storefront-web',
        status: 'running:healthy',
        isSwarm: false,
        buildPack: 'nixpacks',
        hasDockerCompose: true,
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        application: baseApplication(overrides.application),
        heading: { lastDeploymentInfo: null, lastDeploymentLink: '' },
        parameters: { project_uuid: 'p1', environment_uuid: 'e1', application_uuid: 'a1' },
        urls: {
            deploy: '/app/1/deploy',
            restart: '/app/1/restart',
            stop: '/app/1/stop',
            checkStatus: '/app/1/check-status',
        },
        ...overrides,
    };
}

beforeEach(() => {
    postSpy.mockClear();
    mockPermissions = { canAccessTerminal: true };
    vi.spyOn(window, 'confirm');
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('ApplicationHeading', () => {
    it('shows only Deploy when the application is exited', () => {
        render(<ApplicationHeading {...baseProps({ application: { status: 'exited:unhealthy' } })} />);

        expect(screen.getByRole('button', { name: 'Deploy' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Redeploy' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Restart' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Stop' })).not.toBeInTheDocument();
    });

    it('shows Redeploy/Restart/Stop/Force deploy for a running, non-swarm, non-compose app', () => {
        render(<ApplicationHeading {...baseProps()} />);

        expect(screen.getByRole('button', { name: 'Redeploy' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Restart' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Stop' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Force deploy (without cache)' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Deploy' })).not.toBeInTheDocument();
    });

    it('shows "Update Service" instead of Redeploy/Restart, and hides Force deploy, for a Swarm app', () => {
        render(<ApplicationHeading {...baseProps({ application: { isSwarm: true } })} />);

        expect(screen.queryByRole('button', { name: 'Redeploy' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Restart' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Update Service' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Force deploy (without cache)' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Stop' })).toBeInTheDocument();
    });

    it('shows "Please load a Compose file" instead of any action button when a Compose app has none loaded', () => {
        render(<ApplicationHeading {...baseProps({ application: { buildPack: 'dockercompose', hasDockerCompose: false } })} />);

        expect(screen.getByText('Please load a Compose file.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Stop' })).not.toBeInTheDocument();
    });

    it('shows the Terminal link only when canAccessTerminal is true and the app is not Swarm', () => {
        const { rerender } = render(<ApplicationHeading {...baseProps()} />);
        expect(screen.getByRole('link', { name: 'Terminal' })).toBeInTheDocument();

        mockPermissions = { canAccessTerminal: false };
        rerender(<ApplicationHeading {...baseProps()} />);
        expect(screen.queryByRole('link', { name: 'Terminal' })).not.toBeInTheDocument();
    });

    it('hides the Terminal link for a Swarm app even with canAccessTerminal true', () => {
        render(<ApplicationHeading {...baseProps({ application: { isSwarm: true } })} />);
        expect(screen.queryByRole('link', { name: 'Terminal' })).not.toBeInTheDocument();
    });

    it('does not stop the application when the confirm() dialog is declined', () => {
        window.confirm.mockReturnValue(false);
        render(<ApplicationHeading {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'Stop' }).click());

        expect(postSpy).not.toHaveBeenCalled();
    });

    it('stops the application with docker_cleanup:true when confirm() is accepted', () => {
        window.confirm.mockReturnValue(true);
        render(<ApplicationHeading {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'Stop' }).click());

        expect(postSpy).toHaveBeenCalledWith('/app/1/stop', { docker_cleanup: true }, undefined);
    });

    it('redeploys with force_rebuild:false and force-deploys with force_rebuild:true', () => {
        render(<ApplicationHeading {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'Redeploy' }).click());
        expect(postSpy).toHaveBeenCalledWith('/app/1/deploy', { force_rebuild: false }, undefined);

        act(() => screen.getByRole('button', { name: 'Force deploy (without cache)' }).click());
        expect(postSpy).toHaveBeenCalledWith('/app/1/deploy', { force_rebuild: true }, undefined);
    });

    it('restarts via post to urls.restart', () => {
        render(<ApplicationHeading {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'Restart' }).click());

        expect(postSpy).toHaveBeenCalledWith('/app/1/restart', undefined, undefined);
    });

    it('shows the last-deployment link only when heading.lastDeploymentInfo is present', () => {
        const { rerender } = render(<ApplicationHeading {...baseProps()} />);
        expect(screen.queryByText(/ago/)).not.toBeInTheDocument();

        rerender(
            <ApplicationHeading
                {...baseProps({ heading: { lastDeploymentInfo: 'Deployed 2 minutes ago', lastDeploymentLink: '/deployment/abc' } })}
            />,
        );
        expect(screen.getByText('Deployed 2 minutes ago')).toBeInTheDocument();
    });
});
