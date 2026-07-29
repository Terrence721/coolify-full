import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import DockerCleanup from './DockerCleanup';

// Live-verified end-to-end 2026-07-29 during the Server management smoke test (issue #26)
// against the real coolify-smoketest-host: settings save + instant-save checkboxes, Trigger
// Manual Cleanup against a real Docker daemon (real dangling images/build cache genuinely
// reclaimed, confirmed via docker system df before/after), the "Recent executions" list, per-
// execution log detail + cleanup-log command output, and log download all worked correctly.
// Found and fixed a real bug along the way while testing the "cleanup may be stalled" callout
// (temporarily backdated a real execution's created_at, then restored): the text read "ran 3
// days ago ago" - lastExecutionTime already comes from the backend as a Carbon diffForHumans()
// string, which already includes the word "ago" (same convention as execution.finishedHuman
// elsewhere on this page), but the JSX template appended a second, hardcoded " ago".

vi.mock('@inertiajs/react', () => ({
    router: {
        put: vi.fn(),
        post: vi.fn(),
    },
    useForm: (initial) => ({
        data: initial,
        setData: vi.fn(),
        put: vi.fn(),
        processing: false,
        errors: {},
    }),
}));

vi.mock('../../hooks/useTeamChannel', () => ({
    useTeamChannel: () => {},
}));

vi.mock('../../Components/ServerNavbar', () => ({ default: () => <div data-testid="server-navbar" /> }));
vi.mock('../../Components/ServerSidebar', () => ({ default: () => <div data-testid="server-sidebar" /> }));

function baseProps(overrides = {}) {
    return {
        serverNavbar: {},
        sidebar: {},
        canUpdate: true,
        isCloud: false,
        settings: {
            dockerCleanupFrequency: '0 0 * * *',
            dockerCleanupThreshold: 80,
            forceDockerCleanup: false,
            deleteUnusedVolumes: false,
            deleteUnusedNetworks: false,
            disableApplicationImageRetention: false,
        },
        isCleanupStale: false,
        lastExecutionTime: null,
        isSchedulerHealthy: true,
        executions: [],
        updateUrl: '/server/srv-uuid/docker-cleanup',
        manualCleanupUrl: '/server/srv-uuid/docker-cleanup/manual',
        executionsUrl: '/server/srv-uuid/docker-cleanup/executions',
        ...overrides,
    };
}

describe('Server/DockerCleanup', () => {
    it('does not say "ago" twice in the stalled-cleanup callout', () => {
        render(<DockerCleanup {...baseProps({ isCleanupStale: true, lastExecutionTime: '3 days ago' })} />);

        expect(screen.getByText(/The last Docker cleanup ran 3 days ago,/)).toBeInTheDocument();
        expect(screen.queryByText(/ago ago/)).not.toBeInTheDocument();
    });

    it('falls back to "unknown time ago" (grammatically complete, still no duplicate "ago") when lastExecutionTime is null', () => {
        render(<DockerCleanup {...baseProps({ isCleanupStale: true, lastExecutionTime: null })} />);

        expect(screen.getByText(/The last Docker cleanup ran unknown time ago,/)).toBeInTheDocument();
        expect(screen.queryByText(/ago ago/)).not.toBeInTheDocument();
    });

    it('does not show the stalled callout when isCleanupStale is false', () => {
        render(<DockerCleanup {...baseProps({ isCleanupStale: false })} />);
        expect(screen.queryByText(/Docker cleanup ran/)).not.toBeInTheDocument();
    });
});
