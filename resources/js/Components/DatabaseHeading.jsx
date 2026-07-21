import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useTeamChannel } from '../hooks/useTeamChannel';
import ActivityLog from './ActivityLog';

/**
 * Simplified port of livewire/project/database/heading.blade.php, scoped to what
 * Project/Database/Backup/Index needs (Configuration/Logs/Terminal stay Livewire for
 * now, so those nav links are plain <a> tags, not Inertia <Link>). Known v1 gap: the
 * original's mobile dropdown Actions menu is not ported — the desktop action button
 * row is shown at all sizes instead.
 */
export default function DatabaseHeading({ heading, urls }) {
    const { props } = usePage();
    const canAccessTerminal = props.permissions?.canAccessTerminal ?? false;
    const [activityId, setActivityId] = useState(null);
    const [showLogs, setShowLogs] = useState(false);
    const pollRef = useRef(null);

    useEffect(() => {
        if (props.flash?.activityContext === 'database' && props.flash?.activityId) {
            setActivityId(props.flash.activityId);
            setShowLogs(true);
        }
         
    }, [props.flash?.activityId, props.flash?.activityContext]);

    useTeamChannel(['ServiceStatusChanged', 'ServiceChecked'], () => {
        router.reload({ only: ['database', 'heading'] });
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

    function restart() {
        const confirmation = window.confirm(
            'This database will be unavailable during the restart. If the database is currently in use data could be lost. Continue?',
        );
        if (!confirmation) return;
        router.post(urls.restart, {}, { preserveScroll: true });
    }

    function stop() {
        const confirmation = window.confirm('This database will be stopped. If the database is currently in use data could be lost. Continue?');
        if (!confirmation) return;
        router.post(urls.stop, { docker_cleanup: heading.dockerCleanupDefault }, { preserveScroll: true });
    }

    const { project_uuid, environment_uuid, database_uuid } = heading.parameters;
    const base = `/project/${project_uuid}/environment/${environment_uuid}/database/${database_uuid}`;

    return (
        <nav className="pb-6">
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
                    <a className="shrink-0 dark:text-white" href={`${base}/backups`}>
                        Backups
                    </a>
                </nav>
                {heading.isFunctional ? (
                    <div className="flex flex-wrap gap-2 items-center">
                        {!heading.isExited ? (
                            <>
                                <button type="button" onClick={restart}>
                                    Restart
                                </button>
                                <button type="button" onClick={stop}>
                                    Stop
                                </button>
                            </>
                        ) : (
                            <button type="button" onClick={start}>
                                Start
                            </button>
                        )}
                    </div>
                ) : (
                    <div className="text-error">Underlying server is not functional.</div>
                )}
            </div>

            {showLogs && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setShowLogs(false)} />
                    <div className="relative flex h-[80vh] w-full flex-col rounded-sm border border-neutral-200 bg-white p-4 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-4xl">
                        <div className="flex items-center justify-between pb-2">
                            <h3 className="text-xl font-bold">Database Startup</h3>
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
