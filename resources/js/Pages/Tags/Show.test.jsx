import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Show from './Show';

// The Tags index/detail page - the "no tag selected" list view and, once a tag is picked, its
// full detail: tagged applications/services, a redeploy-all action, and a live deployments feed.
// Real logic: a 3s router.reload() poll (replacing the original Livewire wire:poll) that only
// runs while a tag is selected and cleans up on unmount/tag change, a window.confirm gate before
// the destructive redeploy-all action, active-tag highlighting in the tag list, and 3 independent
// empty-state branches (no tags at all, no tagged resources, no running deployments) plus
// status-based border styling per deployment.

const reloadSpy = vi.fn();
const postSpy = vi.fn();
let mockUrl = '/tags/backend';

vi.mock('@inertiajs/react', () => ({
    router: {
        reload: (opts) => reloadSpy(opts),
        post: (url) => postSpy(url),
    },
    usePage: () => ({ url: mockUrl }),
}));

afterEach(() => {
    reloadSpy.mockClear();
    postSpy.mockClear();
    mockUrl = '/tags/backend';
    vi.useRealTimers();
});

function baseProps(overrides = {}) {
    return {
        tags: [
            { name: 'backend', href: '/tags/backend' },
            { name: 'frontend', href: '/tags/frontend' },
        ],
        tag: null,
        applications: [],
        services: [],
        deploymentsPerTagPerServer: {},
        ...overrides,
    };
}

function selectedTag(overrides = {}) {
    return {
        name: 'backend',
        webhook: 'https://coolify.test/webhooks/tags/backend',
        redeployUrl: '/tags/backend/redeploy',
        ...overrides,
    };
}

it('shows the empty-tags message when there are no tags at all', () => {
    render(<Show {...baseProps({ tags: [] })} />);
    expect(screen.getByText('No tags yet defined yet. Go to a resource and add a tag there.')).toBeInTheDocument();
});

it('highlights the active tag in the tag list', () => {
    render(<Show {...baseProps({ tag: selectedTag() })} />);
    expect(screen.getByRole('link', { name: 'backend' })).toHaveClass('dark:bg-coollabs');
    expect(screen.getByRole('link', { name: 'frontend' })).not.toHaveClass('dark:bg-coollabs');
});

it('does not render the Tag Details section when no tag is selected', () => {
    render(<Show {...baseProps()} />);
    expect(screen.queryByText('Tag Details')).not.toBeInTheDocument();
});

it('renders the Tag Details section for the selected tag', () => {
    render(<Show {...baseProps({ tag: selectedTag() })} />);
    expect(screen.getByText('Tag Details')).toBeInTheDocument();
    expect(screen.getByDisplayValue('https://coolify.test/webhooks/tags/backend')).toBeInTheDocument();
});

describe('redeployAll', () => {
    it('posts to the redeploy URL when the confirmation is accepted', () => {
        window.confirm = vi.fn(() => true);
        render(<Show {...baseProps({ tag: selectedTag() })} />);

        screen.getByRole('button', { name: 'Redeploy All' }).click();

        expect(window.confirm).toHaveBeenCalled();
        expect(postSpy).toHaveBeenCalledWith('/tags/backend/redeploy');
    });

    it('does not post when the confirmation is cancelled', () => {
        window.confirm = vi.fn(() => false);
        render(<Show {...baseProps({ tag: selectedTag() })} />);

        screen.getByRole('button', { name: 'Redeploy All' }).click();

        expect(postSpy).not.toHaveBeenCalled();
    });
});

it('renders tagged applications and services', () => {
    render(
        <Show
            {...baseProps({
                tag: selectedTag(),
                applications: [{ href: '/app/1', projectEnvironment: 'prod/main', name: 'api', description: 'API service' }],
                services: [{ href: '/service/1', projectEnvironment: 'prod/main', name: 'cache', description: 'Redis' }],
            })}
        />,
    );
    expect(screen.getByText('api')).toBeInTheDocument();
    expect(screen.getByText('cache')).toBeInTheDocument();
});

describe('deployments feed', () => {
    it('shows "No deployments running." when the map is empty', () => {
        render(<Show {...baseProps({ tag: selectedTag(), deploymentsPerTagPerServer: {} })} />);
        expect(screen.getByText('No deployments running.')).toBeInTheDocument();
    });

    it('groups deployments by server and applies status-based border classes', () => {
        render(
            <Show
                {...baseProps({
                    tag: selectedTag(),
                    deploymentsPerTagPerServer: {
                        'server-1': [
                            { id: 1, deployment_url: '/deploy/1', application_name: 'api', status: 'queued' },
                            { id: 2, deployment_url: '/deploy/2', application_name: 'worker', status: 'in_progress' },
                        ],
                    },
                })}
            />,
        );

        expect(screen.getByText('server-1')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /api/ })).toHaveClass('dark:border-coolgray-300');
        expect(screen.getByRole('link', { name: /worker/ })).toHaveClass('border-warning-500');
    });
});

describe('deployment polling', () => {
    it('polls only deploymentsPerTagPerServer every 3s while a tag is selected', () => {
        vi.useFakeTimers();
        render(<Show {...baseProps({ tag: selectedTag() })} />);

        expect(reloadSpy).not.toHaveBeenCalled();

        act(() => vi.advanceTimersByTime(3000));
        expect(reloadSpy).toHaveBeenCalledWith({ only: ['deploymentsPerTagPerServer'] });

        act(() => vi.advanceTimersByTime(3000));
        expect(reloadSpy).toHaveBeenCalledTimes(2);
    });

    it('does not poll when no tag is selected', () => {
        vi.useFakeTimers();
        render(<Show {...baseProps({ tag: null })} />);

        act(() => vi.advanceTimersByTime(10000));
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('stops polling after unmount', () => {
        vi.useFakeTimers();
        const { unmount } = render(<Show {...baseProps({ tag: selectedTag() })} />);

        unmount();
        act(() => vi.advanceTimersByTime(10000));

        expect(reloadSpy).not.toHaveBeenCalled();
    });
});
