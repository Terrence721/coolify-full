import ApplicationHeading from '../../../Components/ApplicationHeading';
import ConfigurationChecker from '../../../Components/ConfigurationChecker';
import ContainerLogs from '../../../Components/ContainerLogs';
import DatabaseHeading from '../../../Components/DatabaseHeading';
import ServiceHeading from '../../../Components/ServiceHeading';

/**
 * Simplified port of livewire/project/shared/logs.blade.php's 3-way resource-type
 * branch (application/database/service). Reuses each resource type's already-ported
 * Heading component and ContainerLogs (Phase 46). Known v1 gap: containers are always
 * fetched eagerly rather than lazily-on-expand — see ContainerLogs.jsx's own docblock.
 */
export default function Logs({
    type,
    title,
    application,
    heading,
    headingUrls,
    isExited,
    service,
    databaseHeading,
    configurationChecker,
    containerGroups,
    noServerMessage,
    parameters,
}) {
    return (
        <div>
            {type === 'application' && (
                <>
                    <h1>Logs</h1>
                    <ConfigurationChecker configurationChecker={configurationChecker} />
                    <ApplicationHeading application={application} heading={heading} parameters={parameters} urls={headingUrls} />
                </>
            )}
            {type === 'database' && (
                <>
                    <h1>Logs</h1>
                    <ConfigurationChecker configurationChecker={configurationChecker} />
                    <DatabaseHeading heading={databaseHeading} urls={headingUrls} />
                </>
            )}
            {type === 'service' && (
                <>
                    <ConfigurationChecker configurationChecker={configurationChecker} />
                    <ServiceHeading service={service} parameters={parameters} urls={headingUrls} />
                </>
            )}

            <div>
                <h2>Logs</h2>
                {(type === 'database' || type === 'service') && isExited ? (
                    <div className="pt-4">The resource is not running.</div>
                ) : containerGroups.length === 0 ? (
                    <div className="pt-2">{noServerMessage}</div>
                ) : (
                    containerGroups.map((group) => (
                        <div key={group.serverName} className="py-2">
                            <h4>Server: {group.serverName}</h4>
                            {group.containers.length === 0 ? (
                                <div className="pt-2">No containers are running on server: {group.serverName}</div>
                            ) : (
                                <div className="flex flex-col gap-4">
                                    {group.containers.map((container) => (
                                        <div key={container.key}>
                                            <div className="flex items-center gap-2 pb-1">
                                                <h4>{container.displayName}</h4>
                                                {container.pullRequest && <div className="text-sm opacity-70">({container.pullRequest})</div>}
                                            </div>
                                            <ContainerLogs
                                                logLines={container.logLines}
                                                numberOfLines={container.numberOfLines}
                                                showTimestamps={container.showTimestamps}
                                                urls={container.urls}
                                                reloadKeys={['containerGroups']}
                                                queryPrefix={container.queryPrefix}
                                            />
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    ))
                )}
            </div>
        </div>
    );
}
