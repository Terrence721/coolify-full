import { router } from '@inertiajs/react';
import { useState } from 'react';
import PrivateKeyCreateModal from '../../../Components/PrivateKeyCreateModal';

export default function Index({ privateKeys, canCreate, createKeyUrl, generateKeyUrl, cleanupUnusedKeysUrl }) {
    const [showAddModal, setShowAddModal] = useState(false);
    const [showCleanupModal, setShowCleanupModal] = useState(false);

    function cleanupUnusedKeys() {
        setShowCleanupModal(false);
        router.post(cleanupUnusedKeysUrl, {}, { preserveScroll: true });
    }

    return (
        <div>
            <div className="pb-6">
                <h1>Security</h1>
                <div className="subtitle">Security related settings.</div>
                <div className="navbar-main">
                    <nav className="flex items-center gap-6 scrollbar min-h-10">
                        <a href="/security/private-key" className="dark:text-white">
                            Private Keys
                        </a>
                        <a href="/security/cloud-tokens">Cloud Tokens</a>
                        <a href="/security/cloud-init-scripts">Cloud-Init Scripts</a>
                        <a href="/security/api-tokens">API Tokens</a>
                    </nav>
                </div>
            </div>

            <div className="flex gap-2">
                <h2 className="pb-4">Private Keys</h2>
                {canCreate && (
                    <button type="button" onClick={() => setShowAddModal(true)}>
                        + Add
                    </button>
                )}
                {canCreate && (
                    <button type="button" onClick={() => setShowCleanupModal(true)}>
                        Delete unused SSH Keys
                    </button>
                )}
            </div>
            <div className="grid gap-4 lg:grid-cols-2">
                {privateKeys.length === 0 && <div>No private keys found.</div>}
                {privateKeys.map((key) =>
                    key.canView ? (
                        <a key={key.uuid} className="coolbox group" href={key.showUrl}>
                            <div className="flex flex-col justify-center mx-6">
                                <div className="box-title">{key.name}</div>
                                <div className="box-description">
                                    {key.description}
                                    {!key.isInUse && (
                                        <span className="inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium bg-warning-400 text-black">
                                            Unused
                                        </span>
                                    )}
                                </div>
                            </div>
                        </a>
                    ) : (
                        <div
                            key={key.uuid}
                            className="coolbox opacity-60 !cursor-not-allowed hover:bg-transparent dark:hover:bg-transparent"
                            title="You don't have permission to view this private key"
                        >
                            <div className="flex flex-col justify-center mx-6">
                                <div className="box-title">
                                    {key.name}
                                    <span className="ml-2 inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium bg-gray-400 dark:bg-gray-600 text-white">
                                        View Only
                                    </span>
                                </div>
                                <div className="box-description">
                                    {key.description}
                                    {!key.isInUse && (
                                        <span className="inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium bg-warning-400 text-black">
                                            Unused
                                        </span>
                                    )}
                                </div>
                            </div>
                        </div>
                    ),
                )}
            </div>

            <PrivateKeyCreateModal
                open={showAddModal}
                onClose={() => setShowAddModal(false)}
                createKeyUrl={createKeyUrl}
                generateKeyUrl={generateKeyUrl}
                onCreated={() => setShowAddModal(false)}
            />

            {showCleanupModal && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setShowCleanupModal(false)} />
                    <div className="relative flex w-full flex-col rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                        <h3 className="text-2xl font-bold pb-4">Confirm unused SSH Key Deletion?</h3>
                        <ul className="list-disc pl-4 pb-4 text-sm">
                            <li>All unused SSH keys (marked with unused) are permanently deleted.</li>
                        </ul>
                        <div className="flex gap-2 justify-end">
                            <button type="button" onClick={() => setShowCleanupModal(false)}>
                                Cancel
                            </button>
                            <button type="button" onClick={cleanupUnusedKeys}>
                                Delete unused SSH Keys
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
