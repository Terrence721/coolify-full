import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useTeamChannel } from '../hooks/useTeamChannel';
import ActivityLog from './ActivityLog';

/**
 * Simplified port of livewire/project/service/heading.blade.php, scoped to what
 * Project/Service/DatabaseBackups needs (Configuration/Logs/Terminal stay Livewire for
 * now, so those nav links are plain <a> tags, not Inertia <Link>). Known v1 gaps,
 * matching DatabaseHeading.jsx's precedent: the mobile dropdown Actions menu (and its
 * one extra action, "Pull Latest Images & Restart", only reachable from that dropdown)
 * is not ported — the desktop action button row is shown at all sizes instead.
 */
export default function ServiceHeading({ service, parameters, urls }) {
    const { props } = usePage();
    const canAccessTerminal = props.permissions?.canAccessTerminal ?? false;
    const [activityId, setActivityId] = useState(null);
    const [showLogs, setShowLogs] = useState(false);
    const pollRef = useRef(null);

    useEffect(() => {
        if (props.flash?.activityContext === 'service' && props.flash?.activityId) {
            setActivityId(props.flash.activityId);
            setShowLogs(true);
        }
         
    }, [props.flash?.activityId, props.flash?.activityContext]);

    useTeamChannel(['ServiceStatusChanged', 'ServiceChecked'], () => {
        router.reload({ only: ['service', 'configurationChecker'] });
    });

    // Mirrors the original's wire:poll.10000ms="checkStatus" fallback alongside the
    // Echo-driven check, in case a broadcast is missed.
    useEffect(() => {
        pollRef.current = setInterval(() => {
            router.post(urls.checkStatus, {}, { preserveScroll: true, preserveState: true });
        }, 10000);

        return () => clearInterval(pollRef.current);
    }, [urls.checkStatus]);

    function start() {
        router.post(urls.start, {}, { preserveScroll: true });
    }

    function forceDeploy() {
        router.post(urls.forceDeploy, {}, { preserveScroll: true });
    }

    function restart() {
        router.post(urls.restart, {}, { preserveScroll: true });
    }

    function stop() {
        const confirmation = window.confirm('This service will be stopped. All non-persistent data will be deleted. Continue?');
        if (!confirmation) return;
        router.post(urls.stop, { docker_cleanup: true }, { preserveScroll: true });
    }

    const { project_uuid, environment_uuid, service_uuid } = parameters;
    const base = `/project/${project_uuid}/environment/${environment_uuid}/service/${service_uuid}`;
    const isRunning = service.status?.includes('running');
    const isDegraded = service.status?.includes('degraded');
    const isExited = service.status?.includes('exited');

    return (
        <nav className="pb-6">
            <h1>Configuration</h1>
            <div className="navbar-main">
                <nav className="flex min-h-10 w-full flex-nowrap items-center gap-6 overflow-x-scroll whitespace-nowrap">
                    <a className="shrink-0" href={base}>
                        Configuration
                    </a>
                    <a className="shrink-0" href={`${base}/logs`}>
                        Logs
                    </a>
                    {canAccessTerminal && (
                        <a className="shrink-0" href={`${base}/terminal`}>
                            Terminal
                        </a>
                    )}
                </nav>
                {service.isDeployable ? (
                    <div className="flex flex-wrap gap-2 items-center">
                        {isRunning || isDegraded ? (
                            <>
                                <button type="button" onClick={restart}>
                                    Restart
                                </button>
                                <button type="button" className="text-error" onClick={stop}>
                                    Stop
                                </button>
                                {isDegraded && (
                                    <button type="button" onClick={forceDeploy}>
                                        Force Restart
                                    </button>
                                )}
                            </>
                        ) : (
                            <>
                                <button type="button" onClick={start}>
                                    Deploy
                                </button>
                                <button type="button" onClick={forceDeploy}>
                                    Force Deploy
                                </button>
                                {isExited && (
                                    <button
                                        type="button"
                                        className="text-error"
                                        onClick={() => router.post(urls.stop, { docker_cleanup: true }, { preserveScroll: true })}
                                    >
                                        Force Cleanup Containers
                                    </button>
                                )}
                            </>
                        )}
                    </div>
                ) : (
                    <div className="text-error">
                        Unable to deploy.{' '}
                        <a className="underline font-bold" href={`${base}/environment-variables`}>
                            Required environment variables missing.
                        </a>
                    </div>
                )}
            </div>

            {showLogs && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setShowLogs(false)} />
                    <div className="relative flex h-[80vh] w-full flex-col rounded-sm border border-neutral-200 bg-white p-4 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-4xl">
                        <div className="flex items-center justify-between pb-2">
                            <h3 className="text-xl font-bold">Service Startup</h3>
                            <button type="button" onClick={() => setShowLogs(false)}>
                                ✕
                            </button>
                        </div>
                        <ActivityLog activityId={activityId} header="Logs" fullHeight />
                    </div>
                </div>
            )}
        </nav>
    );
}
