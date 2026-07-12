import ContainerLogs from '../../../Components/ContainerLogs';
import ServerNavbar from '../../../Components/ServerNavbar';
import ServerSidebar from '../../../Components/ServerSidebar';

export default function SentinelLogs({ serverNavbar, sidebar, isFunctional, displayName, logLines, numberOfLines, showTimestamps, urls }) {
    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ServerSidebar sidebar={sidebar} />
                <div className="w-full">
                    <h2 className="pb-4">Logs</h2>
                    {isFunctional ? (
                        <ContainerLogs
                            displayName={displayName}
                            logLines={logLines}
                            numberOfLines={numberOfLines}
                            showTimestamps={showTimestamps}
                            urls={urls}
                        />
                    ) : (
                        <div className="text-error">Server is not functional.</div>
                    )}
                </div>
            </div>
        </div>
    );
}
