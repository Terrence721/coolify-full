import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { useTeamChannel } from '../../../../hooks/useTeamChannel';

// Simplified port of livewire/project/application/heading.blade.php. Known v1 gaps,
// same "documented, not silently dropped" pattern as AppLayout's own omissions:
// the resource breadcrumb trail (x-resources.breadcrumbs), the domain-link chips
// (x-applications.links), and the "advanced" button group (x-applications.advanced)
// are not yet ported - this renders a plain title + the core action bar instead.
export default function Heading({ application, heading, parameters, urls }) {
    const pollRef = useRef(null);

    useTeamChannel(['ServiceStatusChanged', 'ServiceChecked'], () => {
        router.reload({ only: ['heading', 'application', 'deployments', 'deploymentsCount', 'configurationChecker'] });
    });

    // Mirrors the original's wire:poll.10000ms="checkStatus" fallback alongside the
    // Echo-driven check, in case a broadcast is missed.
    useEffect(() => {
        pollRef.current = setInterval(() => {
            router.post(urls.checkStatus, {}, { preserveScroll: true, preserveState: true });
        }, 10000);

        return () => clearInterval(pollRef.current);
    }, [urls.checkStatus]);

    function deploy(forceRebuild = false) {
        router.post(urls.deploy, { force_rebuild: forceRebuild });
    }

    function forceDeploy() {
        router.post(urls.deploy, { force_rebuild: true });
    }

    function restart() {
        router.post(urls.restart);
    }

    function stop() {
        const confirmation = window.confirm('This application will be stopped. All non-persistent data will be deleted. Continue?');
        if (!confirmation) return;
        router.post(urls.stop, { docker_cleanup: true });
    }

    const isExited = application.status?.startsWith?.('exited');

    return (
        <nav className="pb-6">
            <div className="flex items-center gap-2">
                <h2>{application.name}</h2>
                {heading.lastDeploymentInfo && (
                    <a href={heading.lastDeploymentLink} target="_blank" rel="noreferrer" className="text-xs underline opacity-70">
                        {heading.lastDeploymentInfo}
                    </a>
                )}
            </div>
            <div className="navbar-main">
                <nav className="flex min-h-10 w-full flex-nowrap items-center gap-6 overflow-x-scroll whitespace-nowrap">
                    <a className="shrink-0" href={route_('project.application.configuration', parameters)}>
                        Configuration
                    </a>
                    <a className="shrink-0 dark:text-white" href={route_('project.application.deployment.index', parameters)}>
                        Deployments
                    </a>
                    <a className="shrink-0" href={route_('project.application.logs', parameters)}>
                        Logs
                    </a>
                </nav>
                <div className="flex flex-wrap gap-2 items-center">
                    {!isExited ? (
                        <>
                            <button type="button" title="With rolling update if possible" onClick={() => deploy(false)}>
                                Redeploy
                            </button>
                            <button type="button" title="Restart without rebuilding" onClick={restart}>
                                Restart
                            </button>
                            <button type="button" onClick={stop}>
                                Stop
                            </button>
                            <button type="button" onClick={forceDeploy}>
                                Force deploy (without cache)
                            </button>
                        </>
                    ) : (
                        <button type="button" onClick={() => deploy(false)}>
                            Deploy
                        </button>
                    )}
                </div>
            </div>
        </nav>
    );
}

// Tiny local helper - the app doesn't have Ziggy yet (still a known open item across
// this whole migration), so nav links here are built from the same `parameters` prop
// the original Blade component receives, matching its own route(name, parameters) calls
// against routes whose path shape is stable and already known client-side.
function route_(name, parameters) {
    const { project_uuid, environment_uuid, application_uuid } = parameters;
    const base = `/project/${project_uuid}/environment/${environment_uuid}/application/${application_uuid}`;
    switch (name) {
        case 'project.application.configuration':
            return base;
        case 'project.application.deployment.index':
            return `${base}/deployment`;
        case 'project.application.logs':
            return `${base}/logs`;
        default:
            return base;
    }
}
