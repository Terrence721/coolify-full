import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ServiceHeading from './ServiceHeading';

// ESLint set-state-in-effect cleanup (issue #33): the flash-triggered log modal effect
// restructured to adjust state during render instead. Previously zero coverage for this
// component at all. Covers the isDeployable/running/degraded/exited button-set gating, the
// canAccessTerminal gate, the Stop window.confirm() gate, the checkStatus poll interval, the
// useTeamChannel-driven reload, and the flash-triggered log modal - including the on-first-
// render case (a flash already present when the page loads), the exact case a naively-seeded
// tracking value would silently miss.

const postSpy = vi.fn();
const reloadSpy = vi.fn();
let mockPermissions = { canAccessTerminal: true };
let mockFlash = {};
let teamChannelCallback = null;

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => postSpy(url, data, options),
        reload: (options) => reloadSpy(options),
    },
    usePage: () => ({ props: { permissions: mockPermissions, flash: mockFlash } }),
}));

vi.mock('../hooks/useTeamChannel', () => ({
    useTeamChannel: (events, callback) => {
        teamChannelCallback = callback;
    },
}));

vi.mock('./ActivityLog', () => ({
    default: ({ activityId, header }) => (
        <div data-testid="activity-log">
            {header} - {activityId}
        </div>
    ),
}));

function baseService(overrides = {}) {
    return {
        status: 'exited',
        isDeployable: true,
        ...overrides,
    };
}

function baseProps({ service: serviceOverrides, ...overrides } = {}) {
    return {
        service: baseService(serviceOverrides),
        parameters: { project_uuid: 'p1', environment_uuid: 'e1', service_uuid: 's1' },
        urls: {
            start: '/service/s1/start',
            stop: '/service/s1/stop',
            restart: '/service/s1/restart',
            forceDeploy: '/service/s1/force-deploy',
            checkStatus: '/service/s1/check-status',
        },
        ...overrides,
    };
}

describe('ServiceHeading', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        mockPermissions = { canAccessTerminal: true };
        mockFlash = {};
        postSpy.mockClear();
        reloadSpy.mockClear();
        teamChannelCallback = null;
        window.confirm = vi.fn(() => true);
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('shows Deploy/Force Deploy when exited, not Restart/Stop', () => {
        render(<ServiceHeading {...baseProps({ service: { status: 'exited' } })} />);
        expect(screen.getByRole('button', { name: 'Deploy' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Force Deploy' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Restart' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Stop' })).not.toBeInTheDocument();
    });

    it('shows Restart/Stop when running, not Deploy/Force Deploy', () => {
        render(<ServiceHeading {...baseProps({ service: { status: 'running' } })} />);
        expect(screen.getByRole('button', { name: 'Restart' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Stop' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Deploy' })).not.toBeInTheDocument();
    });

    it('shows Restart/Stop/Force Restart when degraded', () => {
        render(<ServiceHeading {...baseProps({ service: { status: 'degraded' } })} />);
        expect(screen.getByRole('button', { name: 'Restart' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Stop' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Force Restart' })).toBeInTheDocument();
    });

    it('shows Force Cleanup Containers only when exited', () => {
        const { unmount } = render(<ServiceHeading {...baseProps({ service: { status: 'exited' } })} />);
        expect(screen.getByRole('button', { name: 'Force Cleanup Containers' })).toBeInTheDocument();
        unmount();

        render(<ServiceHeading {...baseProps({ service: { status: 'running' } })} />);
        expect(screen.queryByRole('button', { name: 'Force Cleanup Containers' })).not.toBeInTheDocument();
    });

    it('shows the "Unable to deploy" message instead of action buttons when not deployable', () => {
        render(<ServiceHeading {...baseProps({ service: { isDeployable: false } })} />);
        expect(screen.getByText(/Unable to deploy/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Deploy' })).not.toBeInTheDocument();
    });

    it('only shows the Terminal link when canAccessTerminal is true', () => {
        mockPermissions = { canAccessTerminal: false };
        const { unmount } = render(<ServiceHeading {...baseProps()} />);
        expect(screen.queryByRole('link', { name: 'Terminal' })).not.toBeInTheDocument();
        unmount();

        mockPermissions = { canAccessTerminal: true };
        render(<ServiceHeading {...baseProps()} />);
        expect(screen.getByRole('link', { name: 'Terminal' })).toBeInTheDocument();
    });

    it('asks for confirmation before Stop, and does not post if declined', () => {
        window.confirm = vi.fn(() => false);
        render(<ServiceHeading {...baseProps({ service: { status: 'running' } })} />);

        act(() => screen.getByRole('button', { name: 'Stop' }).click());
        expect(postSpy).not.toHaveBeenCalled();
    });

    it('posts docker_cleanup: true to urls.stop when Stop is confirmed', () => {
        render(<ServiceHeading {...baseProps({ service: { status: 'running' } })} />);

        act(() => screen.getByRole('button', { name: 'Stop' }).click());
        expect(postSpy).toHaveBeenCalledWith('/service/s1/stop', { docker_cleanup: true }, { preserveScroll: true });
    });

    it('polls checkStatus every 10 seconds', () => {
        render(<ServiceHeading {...baseProps()} />);

        expect(postSpy).not.toHaveBeenCalled();
        act(() => vi.advanceTimersByTime(10000));
        expect(postSpy).toHaveBeenCalledWith('/service/s1/check-status', {}, { preserveScroll: true, preserveState: true });
        act(() => vi.advanceTimersByTime(10000));
        expect(postSpy).toHaveBeenCalledTimes(2);
    });

    it('reloads service/configurationChecker when the team channel fires', () => {
        render(<ServiceHeading {...baseProps()} />);

        expect(teamChannelCallback).toBeInstanceOf(Function);
        act(() => teamChannelCallback());

        expect(reloadSpy).toHaveBeenCalledWith({ only: ['service', 'configurationChecker'] });
    });

    it('shows no log modal when flash is empty', () => {
        render(<ServiceHeading {...baseProps()} />);
        expect(screen.queryByTestId('activity-log')).not.toBeInTheDocument();
    });

    it('opens the log modal when a service activity flash is already present on the very first render', () => {
        mockFlash = { activityContext: 'service', activityId: 'act-1' };
        render(<ServiceHeading {...baseProps()} />);
        expect(screen.getByTestId('activity-log')).toHaveTextContent('Logs - act-1');
    });

    it('ignores an activityId flash meant for a different context', () => {
        mockFlash = { activityContext: 'database', activityId: 'act-1' };
        render(<ServiceHeading {...baseProps()} />);
        expect(screen.queryByTestId('activity-log')).not.toBeInTheDocument();
    });

    it('opens the log modal on a later re-render when a new service activity flash arrives', () => {
        const { rerender } = render(<ServiceHeading {...baseProps()} />);
        expect(screen.queryByTestId('activity-log')).not.toBeInTheDocument();

        mockFlash = { activityContext: 'service', activityId: 'act-2' };
        rerender(<ServiceHeading {...baseProps()} />);
        expect(screen.getByTestId('activity-log')).toHaveTextContent('act-2');
    });

    it('closes the log modal via the ✕ button', () => {
        mockFlash = { activityContext: 'service', activityId: 'act-1' };
        render(<ServiceHeading {...baseProps()} />);

        act(() => screen.getByRole('button', { name: '✕' }).click());
        expect(screen.queryByTestId('activity-log')).not.toBeInTheDocument();
    });
});
