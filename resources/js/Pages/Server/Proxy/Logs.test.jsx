import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ProxyLogs from './Logs';

// The /server/{uuid}/proxy/logs page, live-verified during the 2026-07-26 Server management
// smoke test (issue #26): with the proxy stopped, the page correctly surfaced the real Docker
// daemon error ("Error response from daemon: No such container: coolify-proxy") instead of a
// blank page or a crash - a genuinely correct edge case, not a bug. Once the proxy was started,
// real live-streaming Traefik debug logs rendered correctly. ContainerLogs itself (the shared
// component doing the actual streaming work) already has its own dedicated test suite - this
// suite only locks in this page's own thin wrapper logic: the isFunctional gate and prop
// pass-through, previously untested.

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
        displayName: 'coolify-proxy',
        logLines: ['line 1', 'line 2'],
        numberOfLines: 100,
        showTimestamps: true,
        urls: { fetch: '/server/srv-uuid/proxy/logs/fetch', download: '/server/srv-uuid/proxy/logs/download' },
        ...overrides,
    };
}

describe('Server/Proxy/Logs', () => {
    beforeEach(() => {
        containerLogsSpy.mockClear();
    });

    it('renders ContainerLogs with all page props passed through when functional', () => {
        render(<ProxyLogs {...baseProps()} />);

        expect(screen.getByTestId('container-logs')).toBeInTheDocument();
        expect(containerLogsSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                displayName: 'coolify-proxy',
                logLines: ['line 1', 'line 2'],
                numberOfLines: 100,
                showTimestamps: true,
                urls: { fetch: '/server/srv-uuid/proxy/logs/fetch', download: '/server/srv-uuid/proxy/logs/download' },
            }),
        );
    });

    it('shows "Server is not functional." instead of ContainerLogs when isFunctional is false', () => {
        render(<ProxyLogs {...baseProps({ isFunctional: false })} />);

        expect(screen.getByText('Server is not functional.')).toBeInTheDocument();
        expect(screen.queryByTestId('container-logs')).not.toBeInTheDocument();
        expect(containerLogsSpy).not.toHaveBeenCalled();
    });

    it('renders the navbar and sidebar', () => {
        render(<ProxyLogs {...baseProps()} />);
        expect(screen.getByTestId('server-navbar')).toBeInTheDocument();
        expect(screen.getByTestId('server-sidebar')).toBeInTheDocument();
    });
});
