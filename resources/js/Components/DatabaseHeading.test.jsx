import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DatabaseHeading from './DatabaseHeading';

// The exact component whose real bug (missing start/stop/restart/checkStatus urls on the
// Backup Execution page, only this session) crashed this component's own 10s status poll on
// every tick. Covers the isFunctional/isExited button-set gating, Start/Restart/Stop (with the
// window.confirm() gate on the latter two), the checkStatus poll interval, the
// useTeamChannel-driven reload, and the flash-triggered startup-log modal - none of it
// previously tested.

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

function baseHeading(overrides = {}) {
    return {
        isFunctional: true,
        isExited: false,
        dockerCleanupDefault: true,
        parameters: { project_uuid: 'p1', environment_uuid: 'e1', database_uuid: 'd1' },
        ...overrides,
    };
}

function baseProps({ heading: headingOverrides, ...overrides } = {}) {
    return {
        heading: baseHeading(headingOverrides),
        urls: {
            start: '/db/1/start',
            stop: '/db/1/stop',
            restart: '/db/1/restart',
            checkStatus: '/db/1/check-status',
        },
        ...overrides,
    };
}

beforeEach(() => {
    postSpy.mockClear();
    reloadSpy.mockClear();
    mockPermissions = { canAccessTerminal: true };
    mockFlash = {};
    teamChannelCallback = null;
    vi.spyOn(window, 'confirm');
    vi.useFakeTimers();
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
});

describe('DatabaseHeading', () => {
    it('shows Restart and Stop when the database is functional and not exited', () => {
        render(<DatabaseHeading {...baseProps()} />);

        expect(screen.getByRole('button', { name: 'Restart' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Stop' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Start' })).not.toBeInTheDocument();
    });

    it('shows only Start when the database is exited', () => {
        render(<DatabaseHeading {...baseProps({ heading: { isExited: true } })} />);

        expect(screen.getByRole('button', { name: 'Start' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Restart' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Stop' })).not.toBeInTheDocument();
    });

    it('shows the not-functional message and no action buttons when isFunctional is false', () => {
        render(<DatabaseHeading {...baseProps({ heading: { isFunctional: false } })} />);

        expect(screen.getByText('Underlying server is not functional.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Restart' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Stop' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Start' })).not.toBeInTheDocument();
    });

    it('shows the Terminal link only when canAccessTerminal is true', () => {
        const { rerender } = render(<DatabaseHeading {...baseProps()} />);
        expect(screen.getByRole('link', { name: 'Terminal' })).toBeInTheDocument();

        mockPermissions = { canAccessTerminal: false };
        rerender(<DatabaseHeading {...baseProps()} />);
        expect(screen.queryByRole('link', { name: 'Terminal' })).not.toBeInTheDocument();
    });

    it('always shows Configuration, Logs, and Backups nav links pointed at the right URLs', () => {
        render(<DatabaseHeading {...baseProps()} />);

        expect(screen.getByRole('link', { name: 'Configuration' })).toHaveAttribute('href', '/project/p1/environment/e1/database/d1');
        expect(screen.getByRole('link', { name: 'Logs' })).toHaveAttribute('href', '/project/p1/environment/e1/database/d1/logs');
        expect(screen.getByRole('link', { name: 'Backups' })).toHaveAttribute('href', '/project/p1/environment/e1/database/d1/backups');
    });

    it('starts the database via post to urls.start', () => {
        render(<DatabaseHeading {...baseProps({ heading: { isExited: true } })} />);

        act(() => screen.getByRole('button', { name: 'Start' }).click());

        expect(postSpy).toHaveBeenCalledWith('/db/1/start', {}, { preserveScroll: true });
    });

    it('does not restart when the confirm() dialog is declined', () => {
        window.confirm.mockReturnValue(false);
        render(<DatabaseHeading {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'Restart' }).click());

        expect(postSpy).not.toHaveBeenCalled();
    });

    it('restarts via post to urls.restart when confirm() is accepted', () => {
        window.confirm.mockReturnValue(true);
        render(<DatabaseHeading {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'Restart' }).click());

        expect(postSpy).toHaveBeenCalledWith('/db/1/restart', {}, { preserveScroll: true });
    });

    it('does not stop when the confirm() dialog is declined', () => {
        window.confirm.mockReturnValue(false);
        render(<DatabaseHeading {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'Stop' }).click());

        expect(postSpy).not.toHaveBeenCalled();
    });

    it('stops with docker_cleanup from heading.dockerCleanupDefault when confirm() is accepted', () => {
        window.confirm.mockReturnValue(true);
        render(<DatabaseHeading {...baseProps({ heading: { dockerCleanupDefault: false } })} />);

        act(() => screen.getByRole('button', { name: 'Stop' }).click());

        expect(postSpy).toHaveBeenCalledWith('/db/1/stop', { docker_cleanup: false }, { preserveScroll: true });
    });

    it('polls urls.checkStatus every 10 seconds', () => {
        render(<DatabaseHeading {...baseProps()} />);
        expect(postSpy).not.toHaveBeenCalled();

        act(() => vi.advanceTimersByTime(10000));
        expect(postSpy).toHaveBeenCalledWith('/db/1/check-status', {}, { preserveScroll: true, preserveState: true });

        postSpy.mockClear();
        act(() => vi.advanceTimersByTime(10000));
        expect(postSpy).toHaveBeenCalledTimes(1);
    });

    it('reloads database and heading props on a ServiceStatusChanged/ServiceChecked broadcast', () => {
        render(<DatabaseHeading {...baseProps()} />);
        expect(teamChannelCallback).toBeInstanceOf(Function);

        act(() => teamChannelCallback());

        expect(reloadSpy).toHaveBeenCalledWith({ only: ['database', 'heading'] });
    });

    it('opens the startup-log modal when flash carries a database activity context', () => {
        mockFlash = { activityContext: 'database', activityId: 'abc-123' };
        render(<DatabaseHeading {...baseProps()} />);

        expect(screen.getByText('Database Startup')).toBeInTheDocument();
        expect(screen.getByTestId('activity-log')).toHaveTextContent('abc-123');
    });

    it('does not open the startup-log modal when flash has no database activity context', () => {
        render(<DatabaseHeading {...baseProps()} />);

        expect(screen.queryByText('Database Startup')).not.toBeInTheDocument();
    });

    it('closes the startup-log modal via the close button', () => {
        mockFlash = { activityContext: 'database', activityId: 'abc-123' };
        render(<DatabaseHeading {...baseProps()} />);

        act(() => screen.getByRole('button', { name: '✕' }).click());

        expect(screen.queryByText('Database Startup')).not.toBeInTheDocument();
    });
});
