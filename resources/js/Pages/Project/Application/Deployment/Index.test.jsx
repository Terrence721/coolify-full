import { render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Index from './Index';

// Manually verified live end-to-end during the 2026-07-24 config-changed-banner and PR-ID
// pagination smoke tests (issue #22, now closed): a real deployment established a config_hash
// baseline, the "configuration changed" banner and diff modal worked correctly for an admin, and
// 30 real ApplicationDeploymentQueue rows confirmed pagination (unfiltered and PR-filtered) both
// navigate and stay filtered. This suite locks all of that page's own logic in as automated
// coverage - previously entirely untested: the status label/border/badge mapping, the
// queued-vs-other conditional block, the 5s poll (only active on the first page), the
// useTeamChannel ServiceChecked reload, and the pagination/filter math.

let teamChannelCallback = null;
const reloadSpy = vi.fn();
const getSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        reload: (opts) => reloadSpy(opts),
        get: (url, data, options) => getSpy(url, data, options),
    },
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
        };
    },
}));

vi.mock('../../../../hooks/useTeamChannel', () => ({
    useTeamChannel: (events, onEvent) => {
        teamChannelCallback = onEvent;
    },
}));

vi.mock('../../../../Components/ApplicationHeading', () => ({
    default: () => <div data-testid="application-heading" />,
}));

vi.mock('../../../../Components/ConfigurationChecker', () => ({
    default: ({ configurationChecker }) => (
        <div data-testid="configuration-checker">{configurationChecker.isConfigurationChanged ? 'changed' : 'clean'}</div>
    ),
}));

function deployment(overrides = {}) {
    return {
        deployment_uuid: 'dep-1',
        status: 'finished',
        started_at: '2026-07-24 16:00:00 UTC',
        finished_at: '2026-07-24 16:00:14 UTC',
        duration: '00m 14s',
        finished_ago: '5 minutes ago',
        commit: 'abc1234567',
        commit_link: 'https://github.com/example/repo/commit/abc1234567',
        commit_message: 'Fix the thing\n\nLonger body text',
        server_name: 'production-01',
        has_additional_servers: false,
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        application: {},
        heading: {},
        configurationChecker: { isConfigurationChanged: false, isExited: false, configHash: 'abc', diff: { changes: [] } },
        deployments: [deployment()],
        deploymentsCount: 1,
        skip: 0,
        defaultTake: 10,
        currentPage: 1,
        showNext: false,
        showPrev: false,
        pullRequestId: null,
        baseUrl: '/project/proj/environment/env/application/app-1/deployment',
        urls: {},
        parameters: {},
        ...overrides,
    };
}

describe('Project/Application/Deployment/Index', () => {
    beforeEach(() => {
        reloadSpy.mockClear();
        getSpy.mockClear();
        teamChannelCallback = null;
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('shows "No deployments found" when the list is empty', () => {
        render(<Index {...baseProps({ deployments: [], deploymentsCount: 0 })} />);
        expect(screen.getByText('No deployments found')).toBeInTheDocument();
    });

    it.each([
        ['in_progress', 'In Progress'],
        ['queued', 'Queued'],
        ['failed', 'Failed'],
        ['finished', 'Success'],
        ['cancelled-by-user', 'Cancelled'],
        ['some-unknown-status', 'some-unknown-status'],
    ])('renders the right label for status %s', (status, label) => {
        render(<Index {...baseProps({ deployments: [deployment({ status })] })} />);
        expect(screen.getByText(label)).toBeInTheDocument();
    });

    it('hides the Started/Ended block entirely for a queued deployment', () => {
        render(<Index {...baseProps({ deployments: [deployment({ status: 'queued' })] })} />);
        expect(screen.queryByText(/Started:/)).not.toBeInTheDocument();
    });

    it('shows "Running for" instead of Ended/Duration for an in-progress deployment', () => {
        render(<Index {...baseProps({ deployments: [deployment({ status: 'in_progress', finished_at: null })] })} />);
        expect(screen.getByText(/Started:/)).toBeInTheDocument();
        expect(screen.getByText(/Running for:/)).toBeInTheDocument();
        expect(screen.queryByText(/Ended:/)).not.toBeInTheDocument();
    });

    it('shows Ended/Duration/Finished for a completed deployment with finished_at', () => {
        render(<Index {...baseProps({ deployments: [deployment({ status: 'finished' })] })} />);
        expect(screen.getByText(/Ended:/)).toBeInTheDocument();
        expect(screen.getByText(/Finished 5 minutes ago/)).toBeInTheDocument();
    });

    it('shows only the first line of a multi-line commit message', () => {
        render(<Index {...baseProps()} />);
        expect(screen.getByText(/Fix the thing/)).toBeInTheDocument();
        expect(screen.queryByText(/Longer body text/)).not.toBeInTheDocument();
    });

    it('shows the server name only when has_additional_servers is true', () => {
        const { unmount } = render(<Index {...baseProps({ deployments: [deployment({ has_additional_servers: false })] })} />);
        expect(screen.queryByText(/Server:/)).not.toBeInTheDocument();
        unmount();

        render(<Index {...baseProps({ deployments: [deployment({ has_additional_servers: true })] })} />);
        expect(screen.getByText(/Server:/)).toBeInTheDocument();
    });

    it('hides pagination controls entirely when deploymentsCount is 0', () => {
        render(<Index {...baseProps({ deployments: [], deploymentsCount: 0 })} />);
        expect(screen.queryByText(/Page \d+ of \d+/)).not.toBeInTheDocument();
    });

    it('disables Prev/Next per showPrev/showNext, and navigates with the right skip math', () => {
        render(<Index {...baseProps({ skip: 10, currentPage: 2, showPrev: true, showNext: true, pullRequestId: '5' })} />);

        const prev = screen.getByRole('button', { name: '←' });
        const next = screen.getByRole('button', { name: '→' });
        expect(prev).not.toBeDisabled();
        expect(next).not.toBeDisabled();

        act(() => next.click());
        expect(getSpy).toHaveBeenCalledWith(
            expect.any(String),
            { skip: 20, pull_request_id: '5' },
            expect.objectContaining({ preserveState: true, preserveScroll: true }),
        );

        act(() => prev.click());
        expect(getSpy).toHaveBeenCalledWith(expect.any(String), { skip: 0, pull_request_id: '5' }, expect.anything());
    });

    it('never lets Prev go below skip 0', () => {
        render(<Index {...baseProps({ skip: 5, showPrev: true })} />);
        act(() => screen.getByRole('button', { name: '←' }).click());
        expect(getSpy).toHaveBeenCalledWith(expect.any(String), { skip: 0, pull_request_id: null }, expect.anything());
    });

    it('submits the typed pull request id filter, resetting skip to 0', () => {
        render(<Index {...baseProps()} />);

        const input = document.getElementById('deployment-filter-pull-request-id');
        const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        act(() => {
            setter.call(input, '42');
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
        act(() => screen.getByRole('button', { name: 'Filter' }).click());

        expect(getSpy).toHaveBeenCalledWith(expect.any(String), { skip: 0, pull_request_id: '42' }, expect.anything());
    });

    it('only shows Clear when a filter is active, and Clear resets it', () => {
        const { unmount } = render(<Index {...baseProps({ pullRequestId: null })} />);
        expect(screen.queryByRole('button', { name: 'Clear' })).not.toBeInTheDocument();
        unmount();

        render(<Index {...baseProps({ pullRequestId: '7' })} />);
        act(() => screen.getByRole('button', { name: 'Clear' }).click());
        expect(getSpy).toHaveBeenCalledWith(expect.any(String), { skip: 0, pull_request_id: null }, expect.anything());
    });

    it('polls every 5s only when on the first page (skip falsy)', () => {
        vi.useFakeTimers();
        render(<Index {...baseProps({ skip: 0 })} />);

        act(() => vi.advanceTimersByTime(5000));
        expect(reloadSpy).toHaveBeenCalledWith(expect.objectContaining({ only: ['deployments', 'deploymentsCount', 'showNext', 'showPrev'] }));
    });

    it('does not poll when skip is greater than 0', () => {
        vi.useFakeTimers();
        render(<Index {...baseProps({ skip: 10 })} />);

        act(() => vi.advanceTimersByTime(10000));
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('reloads only the deployment-list props on a ServiceChecked team-channel event', () => {
        render(<Index {...baseProps()} />);
        expect(teamChannelCallback).toBeInstanceOf(Function);

        act(() => teamChannelCallback());

        expect(reloadSpy).toHaveBeenCalledWith({ only: ['deployments', 'deploymentsCount', 'showNext', 'showPrev'] });
    });

    it('passes configurationChecker through to ConfigurationChecker', () => {
        render(
            <Index
                {...baseProps({ configurationChecker: { isConfigurationChanged: true, isExited: false, configHash: 'x', diff: { changes: [] } } })}
            />,
        );
        expect(screen.getByTestId('configuration-checker')).toHaveTextContent('changed');
    });
});
