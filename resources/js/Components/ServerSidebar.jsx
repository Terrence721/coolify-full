import { Link } from '@inertiajs/react';

/**
 * React port of resources/views/components/server/sidebar.blade.php (variant "main") and
 * sidebar-security.blade.php (variant "security"). The other two Blade variants
 * (sidebar-proxy.blade.php, sidebar-sentinel.blade.php) are not ported yet — not needed by
 * this phase's 3 pilot pages (Swarm, Security\TerminalAccess, Delete); add them here, following
 * the same shape, when a page using them gets converted.
 */
export default function ServerSidebar({ sidebar }) {
    const item = (key, label, href) => (
        <Link
            key={key}
            className={`sub-menu-item ${sidebar.activeMenu === key ? 'menu-item-active' : ''}`}
            href={href}
        >
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
