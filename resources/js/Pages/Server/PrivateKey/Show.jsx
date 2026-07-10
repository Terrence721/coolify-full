import { router } from '@inertiajs/react';
import { useState } from 'react';
import PrivateKeyCreateModal from '../../../Components/PrivateKeyCreateModal';
import ServerNavbar from '../../../Components/ServerNavbar';
import ServerSidebar from '../../../Components/ServerSidebar';

export default function Show({
    serverNavbar,
    sidebar,
    currentPrivateKeyUuid,
    privateKeys,
    canCreate,
    canUpdate,
    setKeyUrl,
    checkConnectionUrl,
    createKeyUrl,
    generateKeyUrl,
}) {
    const [showAddModal, setShowAddModal] = useState(false);

    function useKey(privateKeyId) {
        router.post(setKeyUrl, { private_key_id: privateKeyId }, { preserveScroll: true });
    }

    function checkConnection() {
        router.post(checkConnectionUrl, {}, { preserveScroll: true });
    }

    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ServerSidebar sidebar={sidebar} />
                <div className="w-full">
                    <div className="flex items-end gap-2">
                        <h2>Private Key</h2>
                        {canCreate && (
                            <button type="button" onClick={() => setShowAddModal(true)}>
                                + Add
                            </button>
                        )}
                        {canUpdate && (
                            <button type="button" onClick={checkConnection}>
                                Check connection
                            </button>
                        )}
                    </div>
                    <div className="pb-4">Change your server's private key.</div>
                    <div className="grid xl:grid-cols-2 grid-cols-1 gap-2">
                        {privateKeys.length === 0 && <div>No private keys found.</div>}
                        {privateKeys.map((key) => (
                            <div
                                key={key.id}
                                className="box-without-bg justify-between dark:bg-coolgray-100 bg-white items-center flex flex-col gap-2"
                            >
                                <div className="flex flex-col w-full">
                                    <div className="box-title">{key.name}</div>
                                    <div className="box-description">{key.description}</div>
                                </div>
                                {currentPrivateKeyUuid !== key.uuid ? (
                                    <button
                                        type="button"
                                        className="w-full"
                                        disabled={!canUpdate}
                                        onClick={() => useKey(key.id)}
                                    >
                                        Use this key
                                    </button>
                                ) : (
                                    <button type="button" className="w-full" disabled>
                                        Currently used
                                    </button>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            <PrivateKeyCreateModal
                open={showAddModal}
                onClose={() => setShowAddModal(false)}
                createKeyUrl={createKeyUrl}
                generateKeyUrl={generateKeyUrl}
                onCreated={() => setShowAddModal(false)}
            />
        </div>
    );
}
