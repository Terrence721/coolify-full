import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTeamChannel } from '../hooks/useTeamChannel';
import ActivityLog from './ActivityLog';

/**
 * React port of App\Livewire\Server\Navbar + resources/views/livewire/server/navbar.blade.php —
 * shared chrome nested by every Server-scoped page (21 pages in the original app). Rendered by
 * each converted page from its `serverNavbar` prop (built server-side by
 * App\Support\ServerChromeData::navbar()).
 *
 * Known v1 gap: the original's Traefik/Sentinel "outdated" warning icons on the Proxy/Sentinel
 * tabs and the mobile-specific layout tweaks are ported; anything not present in the 3 pilot
 * pages that first use this (Swarm, Security\TerminalAccess, Delete) has not been exercised in a
 * real browser yet — see docs/livewire-to-react-migration.md Phase 11.
 */
export default function ServerNavbar({ serverNavbar }) {
    const { props } = usePage();
    const [proxyStatus, setProxyStatus] = useState(serverNavbar.proxyStatus);
    const [showLogs, setShowLogs] = useState(false);
    const [activityId, setActivityId] = useState(null);
    const [lastNotifiedStatus, setLastNotifiedStatus] = useState(null);

    useEffect(() => {
        setProxyStatus(serverNavbar.proxyStatus);
    }, [serverNavbar.proxyStatus]);

    useEffect(() => {
        const flashedActivityId = props.flash?.proxyActivityId;
        if (flashedActivityId) {
            setActivityId(flashedActivityId);
            setShowLogs(true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [props.flash?.proxyActivityId]);

    useTeamChannel(['ProxyStatusChangedUI'], () => {
        router.reload({
            only: ['serverNavbar'],
            onSuccess: (page) => {
                const nextStatus = page.props.serverNavbar?.proxyStatus;
                if (!nextStatus) return;

                if (nextStatus !== lastNotifiedStatus) {
                    if (nextStatus === 'running' && ['exited', 'stopped', 'unknown', null].includes(proxyStatus)) {
                        if (typeof window.toast === 'function') {
                            window.toast('Success', { type: 'success', description: 'Proxy is running.' });
                        }
                        setLastNotifiedStatus(nextStatus);
                    } else if (nextStatus === 'exited' && proxyStatus === 'running') {
                        if (typeof window.toast === 'function') {
                            window.toast('Info', { type: 'info', description: 'Proxy has exited.' });
                        }
                        setLastNotifiedStatus(nextStatus);
                    } else if (nextStatus === 'error') {
                        if (typeof window.toast === 'function') {
                            window.toast('Error', { type: 'danger', description: 'Proxy restart failed. Check logs.' });
                        }
                        setLastNotifiedStatus(nextStatus);
                    }
                }
                setProxyStatus(nextStatus);
            },
        });
    });

    function restart() {
        if (!window.confirm('This proxy will be stopped and started again. All resources hosted on coolify will be unavailable during the restart.')) {
            return;
        }
        setShowLogs(true);
        setActivityId(null);
        router.post(serverNavbar.urls.restart);
    }

    function stop() {
        if (!window.confirm('The coolify proxy will be stopped. All resources hosted on coolify will be unavailable.')) {
            return;
        }
        router.post(serverNavbar.urls.stop);
    }

    function start() {
        setShowLogs(true);
        setActivityId(null);
        router.post(serverNavbar.urls.start);
    }

    function checkStatus() {
        router.post(serverNavbar.urls.checkStatus);
    }

    const isActive = (name) => serverNavbar.currentRouteName === name || serverNavbar.currentRouteName?.startsWith(`${name}.`);

    return (
        <div className="pb-6">
            {showLogs && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setShowLogs(false)} />
                    <div className="relative flex h-[85vh] w-full flex-col rounded-sm border border-neutral-200 bg-white shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-4xl">
                        <div className="flex shrink-0 items-center justify-between border-b border-neutral-200 px-6 py-5 dark:border-coolgray-300">
                            <h3 className="text-2xl font-bold">Proxy Startup Logs</h3>
                            <button type="button" onClick={() => setShowLogs(false)}>✕</button>
                        </div>
                        <div className="flex-1 min-h-0 overflow-hidden p-6">
                            {serverNavbar.server.id === 0 && (
                                <div className="mb-4 p-3 text-sm bg-warning/10 border border-warning/30 rounded-lg text-warning">
                                    <span className="font-semibold">Note:</span> This is the localhost server where Coolify runs.
                                    During proxy restart, the connection may be temporarily lost. If logs stop updating, please
                                    refresh the browser after a few minutes.
                                </div>
                            )}
                            <ActivityLog activityId={activityId} header="Logs" fullHeight />
                        </div>
                    </div>
                </div>
            )}

            <div className="flex items-center gap-2">
                <h1>Server</h1>
                {serverNavbar.proxySet && (
                    <div className="flex items-center gap-2">
                        {proxyStatus === 'running' && <span className="status-badge status-running">Proxy Running</span>}
                        {proxyStatus === 'restarting' && <span className="status-badge status-restarting">Proxy Restarting</span>}
                        {proxyStatus === 'stopping' && <span className="status-badge status-restarting">Proxy Stopping</span>}
                        {proxyStatus === 'starting' && <span className="status-badge status-restarting">Proxy Starting</span>}
                        {serverNavbar.proxyForceStop && <span className="status-badge status-stopped">Proxy Stopped (Force Stop)</span>}
                        {!serverNavbar.proxyForceStop && proxyStatus === 'exited' && <span className="status-badge status-stopped">Proxy Exited</span>}
                        {proxyStatus !== 'exited' && (
                            <button type="button" title="Refresh Status" onClick={checkStatus} className="mx-1">
                                ⟳
                            </button>
                        )}
                    </div>
                )}
                {serverNavbar.isSentinelEnabled && (
                    <span className={`status-badge ${serverNavbar.isSentinelLive ? 'status-running' : 'status-stopped'}`}>
                        {serverNavbar.isSentinelLive ? 'Sentinel In Sync' : 'Sentinel Out of Sync'}
                    </span>
                )}
            </div>
            <div className="subtitle">{serverNavbar.server.name}</div>

            <div className="navbar-main">
                <nav className="flex items-center gap-6 overflow-x-scroll sm:overflow-x-hidden scrollbar min-h-10 whitespace-nowrap pt-2">
                    <Link className={isActive('server.show') ? 'dark:text-white' : ''} href={serverNavbar.urls.show}>
                        Configuration
                    </Link>
                    {!serverNavbar.isSwarmWorker && !serverNavbar.isBuildServer && (
                        <Link className={isActive('server.proxy') ? 'dark:text-white' : ''} href={serverNavbar.urls.proxy}>
                            Proxy
                        </Link>
                    )}
                    {serverNavbar.isFunctional && !serverNavbar.isSwarm && !serverNavbar.isBuildServer && (
                        <Link className={isActive('server.sentinel') ? 'dark:text-white' : ''} href={serverNavbar.urls.sentinel}>
                            Sentinel
                        </Link>
                    )}
                    <Link className={isActive('server.resources') ? 'dark:text-white' : ''} href={serverNavbar.urls.resources}>
                        Resources
                    </Link>
                    {serverNavbar.canAccessTerminal && (
                        <a className={isActive('server.command') ? 'dark:text-white' : ''} href={serverNavbar.urls.command}>
                            Terminal
                        </a>
                    )}
                    {serverNavbar.canUpdate && (
                        <Link className={isActive('server.security') ? 'dark:text-white' : ''} href={serverNavbar.urls.securityPatches}>
                            Security
                        </Link>
                    )}
                </nav>

                <div className="order-first sm:order-last">
                    {serverNavbar.proxySet && (
                        <div className="flex gap-2">
                            {proxyStatus === 'running' ? (
                                <>
                                    {serverNavbar.traefikDashboardAvailable && (
                                        <a target="_blank" rel="noreferrer" href={`http://${serverNavbar.serverIp}:8080`}>
                                            Traefik Dashboard
                                        </a>
                                    )}
                                    <button type="button" onClick={restart}>Restart Proxy</button>
                                    <button type="button" className="text-error" onClick={stop}>Stop Proxy</button>
                                </>
                            ) : (
                                <button type="button" onClick={start}>Start Proxy</button>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
