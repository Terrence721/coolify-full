import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import ServerSidebar from './ServerSidebar';

// Rendered on every single Server\Navbar page (mocked out in all 9 existing Server-page test
// suites) - previously untested itself despite real logic: 4 distinct variants (main/security/
// proxy/sentinel), each with its own active-menu highlighting, and the main variant's 7
// independently-gated conditional nav items (Advanced/Cloudflare Tunnel/Docker Cleanup/
// Destinations/Log Drains/Metrics/Swarm/Danger).

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, className, children }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

function mainSidebar(overrides = {}) {
    return {
        variant: 'main',
        activeMenu: 'general',
        isFunctional: true,
        isLocalhost: false,
        isCloudflareTunnelEnabled: false,
        isBuildServer: false,
        urls: {
            general: '/server/srv-uuid',
            advanced: '/server/srv-uuid/advanced',
            privateKey: '/server/srv-uuid/private-key',
            caCertificate: '/server/srv-uuid/ca-certificate',
            cloudflareTunnel: '/server/srv-uuid/cloudflare-tunnel',
            dockerCleanup: '/server/srv-uuid/docker-cleanup',
            destinations: '/server/srv-uuid/destinations',
            logDrains: '/server/srv-uuid/log-drains',
            metrics: '/server/srv-uuid/metrics',
            swarm: '/server/srv-uuid/swarm',
            delete: '/server/srv-uuid/danger',
        },
        ...overrides,
    };
}

describe('ServerSidebar', () => {
    describe('main variant', () => {
        it('always renders General, Private Key, and CA Certificate', () => {
            render(<ServerSidebar sidebar={mainSidebar()} />);
            expect(screen.getByRole('link', { name: 'General' })).toHaveAttribute('href', '/server/srv-uuid');
            expect(screen.getByRole('link', { name: 'Private Key' })).toHaveAttribute('href', '/server/srv-uuid/private-key');
            expect(screen.getByRole('link', { name: 'CA Certificate' })).toHaveAttribute('href', '/server/srv-uuid/ca-certificate');
        });

        it('marks the item matching activeMenu with menu-item-active, and no other item', () => {
            render(<ServerSidebar sidebar={mainSidebar({ activeMenu: 'private-key' })} />);
            expect(screen.getByRole('link', { name: 'Private Key' })).toHaveClass('menu-item-active');
            expect(screen.getByRole('link', { name: 'General' })).not.toHaveClass('menu-item-active');
        });

        it('hides Advanced when isFunctional is false, shows it when true', () => {
            const { rerender } = render(<ServerSidebar sidebar={mainSidebar({ isFunctional: false })} />);
            expect(screen.queryByRole('link', { name: 'Advanced' })).not.toBeInTheDocument();

            rerender(<ServerSidebar sidebar={mainSidebar({ isFunctional: true })} />);
            expect(screen.getByRole('link', { name: 'Advanced' })).toBeInTheDocument();
        });

        it('hides Cloudflare Tunnel for localhost, shows it otherwise', () => {
            const { rerender } = render(<ServerSidebar sidebar={mainSidebar({ isLocalhost: true })} />);
            expect(screen.queryByRole('link', { name: 'Cloudflare Tunnel' })).not.toBeInTheDocument();

            rerender(<ServerSidebar sidebar={mainSidebar({ isLocalhost: false })} />);
            expect(screen.getByRole('link', { name: 'Cloudflare Tunnel' })).toBeInTheDocument();
        });

        it('hides Docker Cleanup/Destinations/Log Drains/Metrics as a group when isFunctional is false', () => {
            render(<ServerSidebar sidebar={mainSidebar({ isFunctional: false })} />);
            expect(screen.queryByRole('link', { name: 'Docker Cleanup' })).not.toBeInTheDocument();
            expect(screen.queryByRole('link', { name: 'Destinations' })).not.toBeInTheDocument();
            expect(screen.queryByRole('link', { name: 'Log Drains' })).not.toBeInTheDocument();
            expect(screen.queryByRole('link', { name: 'Metrics' })).not.toBeInTheDocument();
        });

        it('shows Swarm only when neither isBuildServer nor isCloudflareTunnelEnabled is true', () => {
            render(<ServerSidebar sidebar={mainSidebar({ isBuildServer: false, isCloudflareTunnelEnabled: false })} />);
            expect(screen.getByRole('link', { name: 'Swarm' })).toBeInTheDocument();
        });

        it('hides Swarm when isBuildServer is true', () => {
            render(<ServerSidebar sidebar={mainSidebar({ isBuildServer: true })} />);
            expect(screen.queryByRole('link', { name: 'Swarm' })).not.toBeInTheDocument();
        });

        it('hides Swarm when isCloudflareTunnelEnabled is true', () => {
            render(<ServerSidebar sidebar={mainSidebar({ isCloudflareTunnelEnabled: true })} />);
            expect(screen.queryByRole('link', { name: 'Swarm' })).not.toBeInTheDocument();
        });

        it('hides Danger for localhost, shows it otherwise', () => {
            const { rerender } = render(<ServerSidebar sidebar={mainSidebar({ isLocalhost: true })} />);
            expect(screen.queryByRole('link', { name: 'Danger' })).not.toBeInTheDocument();

            rerender(<ServerSidebar sidebar={mainSidebar({ isLocalhost: false })} />);
            expect(screen.getByRole('link', { name: 'Danger' })).toBeInTheDocument();
        });
    });

    describe('security variant', () => {
        it('renders Server Patching and Terminal Access, with the active one highlighted', () => {
            render(
                <ServerSidebar
                    sidebar={{
                        variant: 'security',
                        activeMenu: 'terminal-access',
                        urls: { patches: '/server/srv-uuid/security/patches', terminalAccess: '/server/srv-uuid/security/terminal-access' },
                    }}
                />,
            );
            expect(screen.getByRole('link', { name: 'Server Patching' })).toHaveAttribute('href', '/server/srv-uuid/security/patches');
            expect(screen.getByRole('link', { name: 'Terminal Access' })).toHaveClass('menu-item-active');
            expect(screen.getByRole('link', { name: 'Server Patching' })).not.toHaveClass('menu-item-active');
        });
    });

    describe('proxy variant', () => {
        function proxySidebar(overrides = {}) {
            return {
                variant: 'proxy',
                activeMenu: 'configuration',
                proxySet: false,
                urls: {
                    configuration: '/server/srv-uuid/proxy',
                    dynamicConfs: '/server/srv-uuid/proxy/dynamic',
                    logs: '/server/srv-uuid/proxy/logs',
                },
                ...overrides,
            };
        }

        it('always renders Configuration, but hides Dynamic Configurations/Logs when proxySet is false', () => {
            render(<ServerSidebar sidebar={proxySidebar({ proxySet: false })} />);
            expect(screen.getByRole('link', { name: 'Configuration' })).toBeInTheDocument();
            expect(screen.queryByRole('link', { name: 'Dynamic Configurations' })).not.toBeInTheDocument();
            expect(screen.queryByRole('link', { name: 'Logs' })).not.toBeInTheDocument();
        });

        it('shows Dynamic Configurations and Logs, with the active one highlighted, once proxySet is true', () => {
            render(<ServerSidebar sidebar={proxySidebar({ proxySet: true, activeMenu: 'logs' })} />);
            expect(screen.getByRole('link', { name: 'Dynamic Configurations' })).toHaveAttribute('href', '/server/srv-uuid/proxy/dynamic');
            expect(screen.getByRole('link', { name: 'Logs' })).toHaveClass('menu-item-active');
        });
    });

    describe('sentinel variant', () => {
        it('renders Configuration and Logs, with the active one highlighted', () => {
            render(
                <ServerSidebar
                    sidebar={{
                        variant: 'sentinel',
                        activeMenu: 'configuration',
                        urls: { configuration: '/server/srv-uuid/sentinel', logs: '/server/srv-uuid/sentinel/logs' },
                    }}
                />,
            );
            expect(screen.getByRole('link', { name: 'Configuration' })).toHaveClass('menu-item-active');
            expect(screen.getByRole('link', { name: 'Logs' })).toHaveAttribute('href', '/server/srv-uuid/sentinel/logs');
            expect(screen.getByRole('link', { name: 'Logs' })).not.toHaveClass('menu-item-active');
        });
    });
});
