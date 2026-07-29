import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SentinelLogs from './Logs';

// The /server/{uuid}/sentinel/logs page, live-verified during the 2026-07-26 Server management
// smoke test (issue #26): real, live-streaming Sentinel logs rendered correctly - genuine GIN
// HTTP server health-check lines every ~10s and real metrics-push lines every ~60s, confirming
// Sentinel was genuinely alive and actively reporting back to this Coolify instance.
// ContainerLogs itself (the shared component doing the actual streaming work) already has its
// own dedicated test suite - this suite, the sibling of Server/Proxy/Logs.test.jsx, only locks
// in this page's own thin wrapper logic: the isFunctional gate and prop pass-through, previously
// untested.

const containerLogsSpy = vi.fn();

vi.mock('../../../Components/ContainerLogs', () => ({
    default: (props) => {
        containerLogsSpy(props);
        return <div data-testid="container-logs" />;
    },
}));

vi.mock('../../../Components/ServerNavbar', () => ({ default: () => <div data-testid="server-navbar" /> }));
vi.mock('../../../Components/ServerSidebar', () => ({ default: () => <div data-testid="server-sidebar" /> }));

function baseProps(overrides = {}) {
    return {
        serverNavbar: {},
        sidebar: {},
        isFunctional: true,
        displayName: 'coolify-sentinel',
        logLines: ['line 1', 'line 2'],
        numberOfLines: 100,
        showTimestamps: true,
        urls: { fetch: '/server/srv-uuid/sentinel/logs/fetch', download: '/server/srv-uuid/sentinel/logs/download' },
        ...overrides,
    };
}

describe('Server/Sentinel/Logs', () => {
    beforeEach(() => {
        containerLogsSpy.mockClear();
    });

    it('renders ContainerLogs with all page props passed through when functional', () => {
        render(<SentinelLogs {...baseProps()} />);

        expect(screen.getByTestId('container-logs')).toBeInTheDocument();
        expect(containerLogsSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                displayName: 'coolify-sentinel',
                logLines: ['line 1', 'line 2'],
                numberOfLines: 100,
                showTimestamps: true,
                urls: { fetch: '/server/srv-uuid/sentinel/logs/fetch', download: '/server/srv-uuid/sentinel/logs/download' },
            }),
        );
    });

    it('shows "Server is not functional." instead of ContainerLogs when isFunctional is false', () => {
        render(<SentinelLogs {...baseProps({ isFunctional: false })} />);

        expect(screen.getByText('Server is not functional.')).toBeInTheDocument();
        expect(screen.queryByTestId('container-logs')).not.toBeInTheDocument();
        expect(containerLogsSpy).not.toHaveBeenCalled();
    });

    it('renders the navbar and sidebar', () => {
        render(<SentinelLogs {...baseProps()} />);
        expect(screen.getByTestId('server-navbar')).toBeInTheDocument();
        expect(screen.getByTestId('server-sidebar')).toBeInTheDocument();
    });
});
