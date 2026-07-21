import { Link } from '@inertiajs/react';

/**
 * React port of resources/views/components/server/sidebar.blade.php (variant "main"),
 * sidebar-security.blade.php (variant "security"), sidebar-proxy.blade.php (variant
 * "proxy"), and sidebar-sentinel.blade.php (variant "sentinel").
 */
export default function ServerSidebar({ sidebar }) {
    const item = (key, label, href) => (
        <Link key={key} className={`sub-menu-item ${sidebar.activeMenu === key ? 'menu-item-active' : ''}`} href={href}>
            <span className="menu-item-label">{label}</span>
        </Link>
    );

    if (sidebar.variant === 'security') {
        return (
            <div className="sub-menu-wrapper">
                {item('patches', 'Server Patching', sidebar.urls.patches)}
                {item('terminal-access', 'Terminal Access', sidebar.urls.terminalAccess)}
            </div>
        );
    }

    if (sidebar.variant === 'proxy') {
        return (
            <div className="sub-menu-wrapper">
                {item('configuration', 'Configuration', sidebar.urls.configuration)}
                {sidebar.proxySet && (
                    <>
                        <a
                            className={`sub-menu-item ${sidebar.activeMenu === 'dynamic-confs' ? 'menu-item-active' : ''}`}
                            href={sidebar.urls.dynamicConfs}
                        >
                            <span className="menu-item-label">Dynamic Configurations</span>
                        </a>
                        <a className={`sub-menu-item ${sidebar.activeMenu === 'logs' ? 'menu-item-active' : ''}`} href={sidebar.urls.logs}>
                            <span className="menu-item-label">Logs</span>
                        </a>
                    </>
                )}
            </div>
        );
    }

    if (sidebar.variant === 'sentinel') {
        return (
            <div className="sub-menu-wrapper">
                {item('configuration', 'Configuration', sidebar.urls.configuration)}
                <a className={`sub-menu-item ${sidebar.activeMenu === 'logs' ? 'menu-item-active' : ''}`} href={sidebar.urls.logs}>
                    <span className="menu-item-label">Logs</span>
                </a>
            </div>
        );
    }

    return (
        <div className="sub-menu-wrapper">
            {item('general', 'General', sidebar.urls.general)}
            {sidebar.isFunctional && item('advanced', 'Advanced', sidebar.urls.advanced)}
            {item('private-key', 'Private Key', sidebar.urls.privateKey)}
            {sidebar.hasHetznerToken && item('cloud-provider-token', 'Hetzner Token', sidebar.urls.cloudProviderToken)}
            {item('ca-certificate', 'CA Certificate', sidebar.urls.caCertificate)}
            {!sidebar.isLocalhost && item('cloudflare-tunnel', 'Cloudflare Tunnel', sidebar.urls.cloudflareTunnel)}
            {sidebar.isFunctional && (
                <>
                    {item('docker-cleanup', 'Docker Cleanup', sidebar.urls.dockerCleanup)}
                    {item('destinations', 'Destinations', sidebar.urls.destinations)}
                    {item('log-drains', 'Log Drains', sidebar.urls.logDrains)}
                    {item('metrics', 'Metrics', sidebar.urls.metrics)}
                </>
            )}
            {!sidebar.isBuildServer && !sidebar.isCloudflareTunnelEnabled && item('swarm', 'Swarm', sidebar.urls.swarm)}
            {!sidebar.isLocalhost && item('danger', 'Danger', sidebar.urls.delete)}
        </div>
    );
}
