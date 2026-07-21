import { useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ destinations, servers, hasServers, canCreate, createUrl }) {
    const [showAddModal, setShowAddModal] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        name: '',
        network: '',
        server_id: servers[0]?.id ?? '',
    });

    function generateName(serverId) {
        const server = servers.find((s) => String(s.id) === String(serverId));
        const network = data.network || '';
        if (server) {
            setData((prev) => ({ ...prev, name: `${server.name}-${network}`.toLowerCase().replace(/[^a-z0-9-]+/g, '-') }));
        }
    }

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
        post(createUrl, {
            preserveScroll: true,
            onSuccess: () => closeAddModal(),
        });
    }

    return (
        <div>
            <div className="flex items-center gap-2">
                <h1>Destinations</h1>
                {hasServers && canCreate && (
                    <button type="button" onClick={openAddModal}>
                        + Add
                    </button>
                )}
            </div>
            <div className="subtitle">Network endpoints to deploy your resources.</div>
            <div className="grid gap-4 lg:grid-cols-2 -mt-1">
                {destinations.length === 0 && <div>No destinations found.</div>}
                {destinations.map((destination) => (
                    <a key={destination.uuid} className="coolbox group" href={destination.showUrl}>
                        <div className="flex flex-col justify-center mx-6">
                            <div className="box-title">
                                {destination.name}
                                {destination.isSwarm && (
                                    <span className="ml-2 inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium bg-gray-400 dark:bg-gray-600 text-white">
                                        Deprecated
                                    </span>
                                )}
                            </div>
                            <div className="box-description">Server: {destination.serverName}</div>
                        </div>
                    </a>
                ))}
            </div>

            {showAddModal && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={closeAddModal} />
                    <div className="relative flex w-full flex-col rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                        <div className="flex items-center justify-between pb-4">
                            <h3 className="text-2xl font-bold">New Destination</h3>
                            <button type="button" onClick={closeAddModal}>
                                ✕
                            </button>
                        </div>
                        <div className="pb-2 text-sm">Destinations are used to segregate resources by network.</div>
                        <form className="flex flex-col gap-4" onSubmit={submit}>
                            <div className="flex gap-2">
                                <label className="flex flex-col gap-1 w-full">
                                    Name
                                    <input
                                        id="destination-name"
                                        name="destination-name"
                                        required
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                    />
                                    {errors.name && <span className="text-error">{errors.name}</span>}
                                </label>
                                <label className="flex flex-col gap-1 w-full">
                                    Network
                                    <input
                                        id="destination-network"
                                        name="destination-network"
                                        required
                                        value={data.network}
                                        onChange={(e) => setData('network', e.target.value)}
                                    />
                                    {errors.network && <span className="text-error">{errors.network}</span>}
                                </label>
                            </div>
                            <label className="flex flex-col gap-1">
                                Select a server
                                <select
                                    id="destination-server-id"
                                    name="destination-server-id"
                                    required
                                    value={data.server_id}
                                    onChange={(e) => {
                                        setData('server_id', e.target.value);
                                        generateName(e.target.value);
                                    }}
                                >
                                    <option disabled value="">
                                        Select a server
                                    </option>
                                    {servers.map((server) => (
                                        <option key={server.id} value={server.id}>
                                            {server.name}
                                        </option>
                                    ))}
                                </select>
                                {errors.server_id && <span className="text-error">{errors.server_id}</span>}
                            </label>
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
