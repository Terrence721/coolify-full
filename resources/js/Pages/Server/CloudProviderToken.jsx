import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ServerNavbar from '../../Components/ServerNavbar';
import ServerSidebar from '../../Components/ServerSidebar';

export default function CloudProviderToken({
    serverNavbar,
    sidebar,
    hasHetznerServerId,
    currentTokenId,
    canUpdate,
    canCreate,
    tokens,
    setTokenUrl,
    validateTokenUrl,
    createTokenUrl,
}) {
    const [showAddModal, setShowAddModal] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        name: '',
        token: '',
    });

    function openAddModal() {
        reset();
        clearErrors();
        setShowAddModal(true);
    }

    function selectToken(tokenId) {
        router.post(setTokenUrl, { token_id: tokenId }, { preserveScroll: true });
    }

    function validateToken() {
        router.post(validateTokenUrl, {}, { preserveScroll: true });
    }

    function submitCreate(e) {
        e.preventDefault();
        post(createTokenUrl, {
            preserveScroll: true,
            onSuccess: () => setShowAddModal(false),
        });
    }

    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ServerSidebar sidebar={sidebar} />
                <div className="w-full">
                    {hasHetznerServerId ? (
                        <>
                            <div className="flex items-end gap-2">
                                <h2>Hetzner Token</h2>
                                {canCreate && (
                                    <button type="button" onClick={openAddModal}>
                                        + Add
                                    </button>
                                )}
                                {canUpdate && (
                                    <button type="button" onClick={validateToken}>
                                        Validate token
                                    </button>
                                )}
                            </div>
                            <div className="pb-4">Change your server&apos;s Hetzner token.</div>
                            <div className="grid xl:grid-cols-2 grid-cols-1 gap-2">
                                {tokens.length === 0 && <div>No Hetzner tokens found.</div>}
                                {tokens.map((token) => (
                                    <div
                                        key={token.id}
                                        className="box-without-bg justify-between dark:bg-coolgray-100 bg-white items-center flex flex-col gap-2"
                                    >
                                        <div className="flex flex-col w-full">
                                            <div className="box-title">{token.name}</div>
                                            <div className="box-description">Created {token.createdAt}</div>
                                        </div>
                                        {currentTokenId !== token.id ? (
                                            <button type="button" className="w-full" disabled={!canUpdate} onClick={() => selectToken(token.id)}>
                                                Use this token
                                            </button>
                                        ) : (
                                            <button type="button" className="w-full" disabled>
                                                Currently used
                                            </button>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </>
                    ) : (
                        <>
                            <div className="flex items-end gap-2">
                                <h2>Hetzner Token</h2>
                            </div>
                            <div className="pb-4">This server was not created through Hetzner Cloud integration.</div>
                            <div className="p-4 border rounded-md dark:border-coolgray-300 dark:bg-coolgray-100">
                                <p className="dark:text-neutral-400">
                                    Only servers created through Hetzner Cloud can have their tokens managed here.
                                </p>
                            </div>
                        </>
                    )}
                </div>
            </div>

            {showAddModal && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setShowAddModal(false)} />
                    <div className="relative flex w-full flex-col rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                        <div className="flex items-center justify-between pb-4">
                            <h3 className="text-2xl font-bold">Add Hetzner Token</h3>
                            <button type="button" onClick={() => setShowAddModal(false)}>
                                ✕
                            </button>
                        </div>
                        <form className="flex flex-col gap-2" onSubmit={submitCreate}>
                            <label className="flex flex-col gap-1">
                                Token Name
                                <input
                                    id="server-cloud-token-name"
                                    name="server-cloud-token-name"
                                    required
                                    placeholder="e.g., Production Hetzner"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                />
                                {errors.name && <span className="text-error">{errors.name}</span>}
                            </label>
                            <label className="flex flex-col gap-1">
                                API Token
                                <input
                                    id="server-cloud-token-value"
                                    name="server-cloud-token-value"
                                    type="password"
                                    required
                                    placeholder="Enter your API token"
                                    value={data.token}
                                    onChange={(e) => setData('token', e.target.value)}
                                />
                                {errors.token && <span className="text-error">{errors.token}</span>}
                            </label>
                            <div className="text-sm text-neutral-500 dark:text-neutral-400">
                                Create an API token in the{' '}
                                <a href="https://console.hetzner.com/projects" target="_blank" rel="noreferrer" className="underline dark:text-white">
                                    Hetzner Console
                                </a>{' '}
                                → choose Project → Security → API Tokens.
                            </div>
                            <button type="submit" disabled={processing}>
                                Validate & Add Token
                            </button>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
