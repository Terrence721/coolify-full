import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ServerNavbar from '../../../Components/ServerNavbar';
import ServerSidebar from '../../../Components/ServerSidebar';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content;
}

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
    const [publicKey, setPublicKey] = useState('');
    const [generating, setGenerating] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        name: '',
        description: '',
        value: '',
        modal_mode: true,
    });

    function openAddModal() {
        reset();
        clearErrors();
        setPublicKey('');
        setShowAddModal(true);
    }

    function closeAddModal() {
        setShowAddModal(false);
    }

    async function generateKey(type) {
        setGenerating(true);
        try {
            const response = await fetch(generateKeyUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ type }),
            });
            const result = await response.json();
            setData((prev) => ({ ...prev, name: result.name, description: result.description, value: result.value }));
            setPublicKey(result.publicKey);
        } finally {
            setGenerating(false);
        }
    }

    function submitCreate(e) {
        e.preventDefault();
        post(createKeyUrl, {
            preserveScroll: true,
            onSuccess: () => {
                closeAddModal();
            },
        });
    }

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
                            <button type="button" onClick={openAddModal}>
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

            {showAddModal && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={closeAddModal} />
                    <div className="relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-2xl">
                        <div className="flex items-center justify-between pb-4">
                            <h3 className="text-2xl font-bold">New Private Key</h3>
                            <button type="button" onClick={closeAddModal}>
                                ✕
                            </button>
                        </div>
                        <div className="pb-2 text-sm">
                            <div>Private Keys are used to connect to your servers without passwords.</div>
                            <div className="font-bold">You should not use passphrase protected keys.</div>
                        </div>
                        <div className="flex gap-2 mb-4 w-full">
                            <button
                                type="button"
                                className="w-full"
                                disabled={generating}
                                onClick={() => generateKey('ed25519')}
                            >
                                Generate new ED25519 SSH Key
                            </button>
                            <button
                                type="button"
                                className="w-full"
                                disabled={generating}
                                onClick={() => generateKey('rsa')}
                            >
                                Generate new RSA SSH Key
                            </button>
                        </div>
                        <form className="flex flex-col gap-2" onSubmit={submitCreate}>
                            <div className="flex gap-2">
                                <label className="flex flex-col gap-1 w-full">
                                    Name
                                    <input required value={data.name} onChange={(e) => setData('name', e.target.value)} />
                                    {errors.name && <span className="text-error">{errors.name}</span>}
                                </label>
                                <label className="flex flex-col gap-1 w-full">
                                    Description
                                    <input
                                        value={data.description}
                                        onChange={(e) => setData('description', e.target.value)}
                                    />
                                    {errors.description && <span className="text-error">{errors.description}</span>}
                                </label>
                            </div>
                            <label className="flex flex-col gap-1">
                                Private Key
                                <textarea
                                    rows="10"
                                    className="font-mono"
                                    placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                                    required
                                    value={data.value}
                                    onChange={(e) => setData('value', e.target.value)}
                                />
                                {errors.value && <span className="text-error">{errors.value}</span>}
                            </label>
                            {publicKey && (
                                <label className="flex flex-col gap-1">
                                    Public Key
                                    <input readOnly value={publicKey} />
                                </label>
                            )}
                            {publicKey && (
                                <span className="pt-2 pb-4 font-bold dark:text-warning">
                                    ACTION REQUIRED: Copy the 'Public Key' to your server's ~/.ssh/authorized_keys file
                                </span>
                            )}
                            <button type="submit" disabled={processing}>
                                Continue
                            </button>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
