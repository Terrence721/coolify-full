import { router } from '@inertiajs/react';
import { useState } from 'react';
import PasswordConfirmModal from './PasswordConfirmModal';

/**
 * React port of App\Livewire\Project\Shared\Destination — multi-server deployment management
 * for an Application: the primary server card, additional-server cards (each with its own
 * Deploy/Promote-to-Primary/Stop/Remove actions), and an "Add another server" picker built from
 * the same eligible-network computation as the original's loadData() (usable servers, minus
 * networks already attached, minus the primary server's own network, minus any server that
 * already has an additional network attached). Application-only — no Database/Service
 * equivalent exists, so this stays inline with no shared concern, same as Swarm/Rollback.
 */
function StatusBadge({ status }) {
    const value = status ?? '';
    if (value.startsWith('running')) return <div title={status} className="absolute bg-success -top-1 -left-1 badge" />;
    if (value.startsWith('exited')) return <div title={status} className="absolute bg-error -top-1 -left-1 badge" />;
    return null;
}

export default function ApplicationServersTab({ servers, serversUrls, canUpdate }) {
    const [removing, setRemoving] = useState(null);

    function redeploy(networkId, serverId) {
        router.post(serversUrls.redeploy, { networkId, serverId }, { preserveScroll: true });
    }

    function stop(serverId) {
        router.post(serversUrls.stop, { serverId }, { preserveScroll: true });
    }

    function promote(networkId, serverId) {
        router.post(serversUrls.promote, { networkId, serverId }, { preserveScroll: true });
    }

    function addServer(networkId, serverId) {
        router.post(serversUrls.add, { networkId, serverId }, { preserveScroll: true });
    }

    const hasAdditional = servers.additionalNetworks.length > 0;

    return (
        <div>
            <h2>Servers</h2>
            <div>Server related configurations.</div>
            <div className="grid grid-cols-1 gap-4 py-4">
                <div className="flex flex-col gap-2">
                    <h3>Primary Server</h3>
                    <div className="relative flex flex-col bg-white border cursor-default dark:text-white box-without-bg dark:bg-coolgray-100 dark:border-coolgray-300">
                        <StatusBadge status={servers.primary.status} />
                        <div className="box-title">Server: {servers.primary.serverName}</div>
                        <div className="box-description">Network: {servers.primary.network}</div>
                    </div>
                    {hasAdditional && canUpdate && (
                        <div className="flex gap-2">
                            <button type="button" onClick={() => redeploy(servers.primary.networkId, servers.primary.serverId)}>
                                Deploy
                            </button>
                            {servers.primary.status?.startsWith('running') && (
                                <button type="button" className="button-error" onClick={() => stop(servers.primary.serverId)}>
                                    Stop
                                </button>
                            )}
                        </div>
                    )}
                </div>

                {hasAdditional && servers.canManageAdditionalServers && (
                    <>
                        <h3>Additional Server(s)</h3>
                        {servers.additionalNetworks.map((network) => (
                            <div key={network.id} className="flex flex-col gap-2">
                                <div className="relative flex flex-col bg-white border cursor-default dark:text-white box-without-bg dark:bg-coolgray-100 dark:border-coolgray-300">
                                    <StatusBadge status={network.status} />
                                    <div className="box-title">Server: {network.serverName}</div>
                                    <div className="box-description">Network: {network.network}</div>
                                </div>
                                {canUpdate && (
                                    <div className="flex gap-2 flex-wrap">
                                        <button type="button" onClick={() => redeploy(network.id, network.serverId)}>
                                            Deploy
                                        </button>
                                        <button type="button" onClick={() => promote(network.id, network.serverId)}>
                                            Promote to Primary
                                        </button>
                                        {network.isRunning && (
                                            <button type="button" className="button-error" onClick={() => stop(network.serverId)}>
                                                Stop
                                            </button>
                                        )}
                                        <button type="button" className="button-error" onClick={() => setRemoving(network)}>
                                            Remove from server
                                        </button>
                                    </div>
                                )}
                            </div>
                        ))}
                    </>
                )}
            </div>

            {servers.canManageAdditionalServers && (
                <div className="flex flex-col gap-2">
                    {servers.hasPersistentStorage ? (
                        <>
                            <h3>Add another server</h3>
                            <div className="w-full p-3 text-sm rounded bg-warning/10 text-warning">
                                <strong>Cannot add additional servers.</strong> This application has persistent storage volumes configured.
                                Applications with persistent storage cannot be deployed to multiple servers as the storage would not be accessible
                                across different servers.
                            </div>
                        </>
                    ) : servers.availableNetworks.length > 0 ? (
                        <>
                            <h3>Add another server</h3>
                            <div className="grid grid-cols-1 gap-4">
                                {servers.availableNetworks.map((network) => (
                                    <div
                                        key={network.id}
                                        onClick={() => canUpdate && addServer(network.id, network.serverId)}
                                        className="relative flex flex-col dark:text-white coolbox group cursor-pointer"
                                    >
                                        <div>
                                            <div className="box-title">Server: {network.serverName}</div>
                                            <div className="box-description">Network: {network.name}</div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </>
                    ) : (
                        <div>No additional servers available to attach.</div>
                    )}
                </div>
            )}

            {removing && (
                <PasswordConfirmModal
                    title="Confirm removing application from server?"
                    action={{ method: 'delete', url: serversUrls.remove, data: { networkId: removing.id, serverId: removing.serverId } }}
                    actions={['This will stop the all running applications on this server and remove it as a deployment destination.']}
                    confirmationText={removing.serverName}
                    confirmationLabel="Please confirm the execution of the actions by entering the Server Name below"
                    onClose={() => setRemoving(null)}
                    onDone={() => setRemoving(null)}
                />
            )}
        </div>
    );
}
