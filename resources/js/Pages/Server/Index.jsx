import { useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ servers, canCreate, limitReached, privateKeys, defaultPrivateKeyId, defaultName, storeUrl }) {
    const [showAddModal, setShowAddModal] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        name: defaultName,
        description: '',
        ip: '',
        user: 'root',
        port: 22,
        private_key_id: defaultPrivateKeyId ?? '',
        is_build_server: false,
    });

    function openAddModal() {
        reset();
        clearErrors();
        setShowAddModal(true);
    }

    function closeAddModal() {
        setShowAddModal(false);
    }

    function submit(e) {
        e.preventDefault();
        post(storeUrl, {
            preserveScroll: true,
            onSuccess: () => closeAddModal(),
        });
    }

    return (
        <div>
            <div className="flex items-center gap-2">
                <h1>Servers</h1>
                {canCreate && (
                    <button type="button" onClick={openAddModal}>
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
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={closeAddModal} />
                    <div className="relative flex w-full flex-col rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                        <div className="flex items-center justify-between pb-4">
                            <h3 className="text-2xl font-bold">Add Server by IP Address</h3>
                            <button type="button" onClick={closeAddModal}>
                                ✕
                            </button>
                        </div>
                        {limitReached ? (
                            <div className="text-error">You have reached the server limit for your team.</div>
                        ) : (
                            <form className="flex flex-col gap-2" onSubmit={submit}>
                                <div className="flex w-full gap-2 flex-wrap sm:flex-nowrap">
                                    <label className="flex flex-col gap-1 w-full">
                                        Name
                                        <input required value={data.name} onChange={(e) => setData('name', e.target.value)} />
                                        {errors.name && <span className="text-error">{errors.name}</span>}
                                    </label>
                                    <label className="flex flex-col gap-1 w-full">
                                        Description
                                        <input value={data.description} onChange={(e) => setData('description', e.target.value)} />
                                        {errors.description && <span className="text-error">{errors.description}</span>}
                                    </label>
                                </div>
                                <div className="flex gap-2 flex-wrap sm:flex-nowrap">
                                    <label className="flex flex-col gap-1 w-full">
                                        IP Address/Domain
                                        <input required value={data.ip} onChange={(e) => setData('ip', e.target.value)} />
                                        {errors.ip && <span className="text-error">{errors.ip}</span>}
                                    </label>
                                    <label className="flex flex-col gap-1">
                                        Port
                                        <input
                                            type="number"
                                            required
                                            value={data.port}
                                            onChange={(e) => setData('port', e.target.value)}
                                        />
                                        {errors.port && <span className="text-error">{errors.port}</span>}
                                    </label>
                                </div>
                                <label className="flex flex-col gap-1">
                                    User
                                    <input required value={data.user} onChange={(e) => setData('user', e.target.value)} />
                                    {errors.user && <span className="text-error">{errors.user}</span>}
                                </label>
                                <div className="text-xs dark:text-warning text-coollabs">
                                    Non-root user is experimental:{' '}
                                    <a
                                        className="font-bold underline"
                                        target="_blank"
                                        rel="noreferrer"
                                        href="https://coolify.io/docs/knowledge-base/server/non-root-user"
                                    >
                                        docs
                                    </a>
                                    .
                                </div>
                                <label className="flex flex-col gap-1">
                                    Private Key
                                    <select
                                        required
                                        value={data.private_key_id}
                                        onChange={(e) => setData('private_key_id', e.target.value)}
                                    >
                                        <option disabled value="">
                                            Select a private key
                                        </option>
                                        {privateKeys.map((key) => (
                                            <option key={key.id} value={key.id}>
                                                {key.name}
                                            </option>
                                        ))}
                                    </select>
                                    {errors.private_key_id && <span className="text-error">{errors.private_key_id}</span>}
                                </label>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={data.is_build_server}
                                        onChange={(e) => setData('is_build_server', e.target.checked)}
                                    />
                                    Use it as a build server?
                                </label>
                                <button type="submit" disabled={processing}>
                                    Continue
                                </button>
                            </form>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
