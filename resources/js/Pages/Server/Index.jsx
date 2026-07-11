import { useState } from 'react';
import AddServerModal from '../../Components/AddServerModal';

export default function Index({ servers, canCreate, limitReached, privateKeys, defaultPrivateKeyId, defaultName, storeUrl }) {
    const [showAddModal, setShowAddModal] = useState(false);

    return (
        <div>
            <div className="flex items-center gap-2">
                <h1>Servers</h1>
                {canCreate && (
                    <button type="button" onClick={() => setShowAddModal(true)}>
                        + Add
                    </button>
                )}
            </div>
            <div className="subtitle">All your servers are here.</div>
            <div className="grid gap-4 lg:grid-cols-2 -mt-1">
                {servers.length === 0 && (
                    <div>No servers found. Without a server, you won't be able to do much.</div>
                )}
                {servers.map((server) => (
                    <a
                        key={server.uuid}
                        href={server.showUrl}
                        className={`gap-2 border cursor-pointer coolbox group ${
                            !server.isReachable || server.forceDisabled ? 'border-red-500' : ''
                        }`}
                    >
                        <div className="flex flex-col justify-center mx-6">
                            <div className="font-bold dark:text-white">{server.name}</div>
                            <div className="description">{server.description}</div>
                            <div className="flex gap-1 text-xs text-error">
                                {!server.isReachable && <span>Not reachable</span>}
                                {!server.isReachable && !server.isUsable && <span>&amp;</span>}
                                {!server.isUsable && <span>Not usable by Coolify</span>}
                                {server.forceDisabled && <span>Disabled by the system</span>}
                            </div>
                        </div>
                        <div className="flex-1"></div>
                    </a>
                ))}
            </div>

            {showAddModal && (
                <AddServerModal
                    privateKeys={privateKeys}
                    defaultPrivateKeyId={defaultPrivateKeyId}
                    defaultName={defaultName}
                    limitReached={limitReached}
                    storeUrl={storeUrl}
                    onClose={() => setShowAddModal(false)}
                />
            )}
        </div>
    );
}
