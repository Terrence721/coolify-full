import { useEffect, useRef, useState } from 'react';
import ConfigurationChecker from '../../../Components/ConfigurationChecker';
import DatabaseHeading from '../../../Components/DatabaseHeading';
import Heading from '../Application/Deployment/Heading';
import { useApexChart } from '../../../hooks/useApexChart';

const CPU_COLOR = '#1e90ff';
const RAM_COLOR = '#00ced1';

function applicationBase(parameters) {
    const { project_uuid, environment_uuid, application_uuid } = parameters;

    return `/project/${project_uuid}/environment/${environment_uuid}/application/${application_uuid}`;
}

function databaseBase(parameters) {
    const { project_uuid, environment_uuid, database_uuid } = parameters;

    return `/project/${project_uuid}/environment/${environment_uuid}/database/${database_uuid}`;
}

function ApplicationSidebar({ parameters, sidebarFlags }) {
    const base = applicationBase(parameters);

    return (
        <div className="sub-menu-wrapper">
            <a className="sub-menu-item" href={base}>General</a>
            <a className="sub-menu-item" href={`${base}/advanced`}>Advanced</a>
            {sidebarFlags.isSwarm && <a className="sub-menu-item" href={`${base}/swarm`}>Swarm</a>}
            <a className="sub-menu-item" href={`${base}/environment-variables`}>Environment Variables</a>
            <a className="sub-menu-item" href={`${base}/persistent-storage`}>Persistent Storage</a>
            {sidebarFlags.isGitBased && <a className="sub-menu-item" href={`${base}/source`}>Git Source</a>}
            <a className="sub-menu-item" href={`${base}/servers`}>Servers</a>
            <a className="sub-menu-item" href={`${base}/scheduled-tasks`}>Scheduled Tasks</a>
            <a className="sub-menu-item" href={`${base}/webhooks`}>Webhooks</a>
            {(sidebarFlags.isGitBased || sidebarFlags.isDockerImage) && (
                <a className="sub-menu-item" href={`${base}/preview-deployments`}>Preview Deployments</a>
            )}
            {!sidebarFlags.isDockerCompose && <a className="sub-menu-item" href={`${base}/healthcheck`}>Healthcheck</a>}
            <a className="sub-menu-item" href={`${base}/rollback`}>Rollback</a>
            <a className="sub-menu-item" href={`${base}/resource-limits`}>Resource Limits</a>
            <a className="sub-menu-item" href={`${base}/resource-operations`}>Resource Operations</a>
            <a className="sub-menu-item menu-item-active" href={`${base}/metrics`}>Metrics</a>
            <a className="sub-menu-item" href={`${base}/tags`}>Tags</a>
            <a className="sub-menu-item" href={`${base}/danger`}>Danger Zone</a>
        </div>
    );
}

function DatabaseSidebar({ parameters, sidebarFlags }) {
    const base = databaseBase(parameters);

    return (
        <div className="sub-menu-wrapper">
            <a className="sub-menu-item" href={base}>General</a>
            <a className="sub-menu-item" href={`${base}/environment-variables`}>Environment Variables</a>
            <a className="sub-menu-item" href={`${base}/servers`}>Servers</a>
            <a className="sub-menu-item" href={`${base}/persistent-storage`}>Persistent Storage</a>
            {sidebarFlags.canUpdate && <a className="sub-menu-item" href={`${base}/import-backup`}>Import Backup</a>}
            <a className="sub-menu-item" href={`${base}/webhooks`}>Webhooks</a>
            <a className="sub-menu-item" href={`${base}/healthcheck`}>Healthcheck</a>
            <a className="sub-menu-item" href={`${base}/resource-limits`}>Resource Limits</a>
            <a className="sub-menu-item" href={`${base}/resource-operations`}>Resource Operations</a>
            <a className="sub-menu-item menu-item-active" href={`${base}/metrics`}>Metrics</a>
            <a className="sub-menu-item" href={`${base}/tags`}>Tags</a>
            <a className="sub-menu-item" href={`${base}/danger`}>Danger Zone</a>
        </div>
    );
}

function MetricsCharts({ dataUrl }) {
    const [interval, setInterval_] = useState(5);
    const [poll, setPoll] = useState(true);
    const pollTimerRef = useRef(null);

    const updateCpuChart = useApexChart('resource-cpu', 'CPU %', '%', CPU_COLOR);
    const updateMemoryChart = useApexChart('resource-memory', 'Memory', ' MB', RAM_COLOR);

    async function loadData(currentInterval) {
        try {
            const response = await fetch(`${dataUrl}?interval=${currentInterval}`, { headers: { Accept: 'application/json' } });
            const result = await response.json();
            if (result.cpu) {
                updateCpuChart(result.cpu, { labels: { formatter: (value) => `${Math.round(value)} %` } });
            }
            if (result.memory) {
                updateMemoryChart(result.memory, { min: 0, labels: { formatter: (value) => `${Math.round(value)} MB` } });
            }
        } catch {
            // Chart simply keeps showing "Loading..." if a fetch fails; next poll will retry.
        }
    }

    useEffect(() => {
        loadData(interval);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (poll || interval <= 10) {
            pollTimerRef.current = setInterval(() => {
                loadData(interval);
                if (interval > 10) {
                    setPoll(false);
                }
            }, 5000);
        }

        return () => {
            if (pollTimerRef.current) {
                clearInterval(pollTimerRef.current);
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [interval, poll]);

    function changeInterval(e) {
        const value = Number(e.target.value);
        setInterval_(value);
        if (value <= 10) {
            setPoll(true);
        }
        loadData(value);
    }

    return (
        <div>
            <label className="flex flex-col gap-1 max-w-xs">
                Interval
                <select id="interval" name="interval" value={interval} onChange={changeInterval}>
                    <option value={5}>5 minutes (live)</option>
                    <option value={10}>10 minutes (live)</option>
                    <option value={30}>30 minutes</option>
                    <option value={60}>1 hour</option>
                    <option value={720}>12 hours</option>
                    <option value={10080}>1 week</option>
                    <option value={43200}>30 days</option>
                </select>
            </label>
            <h4 className="pt-4">CPU Usage</h4>
            <div id="resource-cpu"></div>
            <h4 className="pt-4">Memory Usage</h4>
            <div id="resource-memory"></div>
        </div>
    );
}

/**
 * Port of livewire/project/shared/metrics.blade.php's application/database branches,
 * reached at project.application.metrics/project.database.metrics — the only two routes
 * repointed off App\Livewire\Project\Application\Configuration and
 * App\Livewire\Project\Database\Configuration (both still-Livewire routers; every other
 * tab route is untouched). Reuses the ApexCharts setup Server/Metrics.jsx already proved,
 * extracted into hooks/useApexChart.js.
 */
export default function Metrics({
    resourceType,
    application,
    heading,
    databaseHeading,
    headingUrls,
    configurationChecker,
    parameters,
    isUnavailable,
    isMetricsEnabled,
    isRunning,
    serverMetricsUrl,
    dataUrl,
    sidebarFlags,
}) {
    return (
        <div>
            <h1>Configuration</h1>
            <ConfigurationChecker configurationChecker={configurationChecker} />
            {resourceType === 'application' && <Heading application={application} heading={heading} parameters={parameters} urls={headingUrls} />}
            {resourceType === 'database' && <DatabaseHeading heading={databaseHeading} urls={headingUrls} />}

            <div className="flex flex-col h-full gap-8 sm:flex-row">
                {resourceType === 'application' ? (
                    <ApplicationSidebar parameters={parameters} sidebarFlags={sidebarFlags} />
                ) : (
                    <DatabaseSidebar parameters={parameters} sidebarFlags={sidebarFlags} />
                )}
                <div className="w-full sm:flex-grow">
                    <div className="flex items-center gap-2">
                        <h2>Metrics</h2>
                    </div>
                    <div className="pb-4">
                        Basic metrics for your {resourceType === 'application' ? 'application' : 'database'} container.
                    </div>

                    {isUnavailable ? (
                        <div className="p-3 border border-yellow-500/30 bg-yellow-500/10 text-sm rounded">
                            Metrics are not available for Docker Compose applications yet!
                        </div>
                    ) : !isMetricsEnabled ? (
                        <div className="p-3 border border-sky-500/30 bg-sky-500/10 text-sm rounded">
                            Metrics are only available for servers with Sentinel &amp; Metrics enabled. Go to{' '}
                            <a className="underline font-semibold" href={serverMetricsUrl}>
                                Server Metrics
                            </a>{' '}
                            to enable it.
                        </div>
                    ) : !isRunning ? (
                        <div className="p-3 border border-yellow-500/30 bg-yellow-500/10 text-sm rounded">
                            Metrics are only available when the container is running!
                        </div>
                    ) : (
                        <MetricsCharts dataUrl={dataUrl} />
                    )}
                </div>
            </div>
        </div>
    );
}
