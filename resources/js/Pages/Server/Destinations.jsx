import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ServerNavbar from '../../Components/ServerNavbar';
import ServerSidebar from '../../Components/ServerSidebar';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content;
}

export default function Destinations({
    serverNavbar,
    sidebar,
    isFunctional,
    canUpdate,
    canCreate,
    standaloneDockers,
    swarmDockers,
    servers,
    scanUrl,
    addUrl,
    createUrl,
}) {
    const [showAddModal, setShowAddModal] = useState(false);
    const [scanning, setScanning] = useState(false);
    const [foundNetworks, setFoundNetworks] = useState(null);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        name: '',
        network: '',
        server_id: servers[0]?.id ?? '',
    });

    function openAddModal() {
        reset();
        clearErrors();
        setShowAddModal(true);
    }

    async function scan() {
        setScanning(true);
        try {
            const response = await fetch(scanUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            });
            const result = await response.json();
            setFoundNetworks(result.networks ?? []);
        } finally {
            setScanning(false);
        }
    }

    function addNetwork(name) {
        router.post(addUrl, { name }, { preserveScroll: true });
    }

    function submitCreate(e) {
        e.preventDefault();
        post(createUrl, {
            onSuccess: () => setShowAddModal(false),
        });
    }

    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ServerSidebar sidebar={sidebar} />
                <div className="w-full">
                    {isFunctional ? (
                        <>
                            <div className="flex items-end gap-2">
                                <h2>Destinations</h2>
                                {canCreate && (
                                    <button type="button" onClick={openAddModal}>
                                        + Add
                                    </button>
                                )}
                                {canUpdate && (
                                    <button type="button" onClick={scan} disabled={scanning}>
                                        {scanning ? 'Scanning...' : 'Scan for Destinations'}
                                    </button>
                                )}
                            </div>
                            <div>Destinations are used to segregate resources by network.</div>
                            <h4 className="pt-4 pb-2">Available Destinations</h4>
                            <div className="flex gap-2 flex-wrap">
                                {[...standaloneDockers, ...swarmDockers].map((docker) => (
                                    <a key={docker.uuid} href={docker.showUrl}>
                                        <button type="button">{docker.network}</button>
                                    </a>
                                ))}
                                {standaloneDockers.length === 0 && swarmDockers.length === 0 && (
                                    <div className="text-sm text-neutral-500">No destinations configured for this server yet.</div>
                                )}
                            </div>
                            {foundNetworks && foundNetworks.length > 0 && (
                                <div className="pt-2">
                                    <h3 className="pb-4">Found Destinations</h3>
                                    <div className="flex flex-wrap gap-2">
                                        {foundNetworks.map((network) => (
                                            <div key={network.name} className="min-w-fit">
                                                <button type="button" onClick={() => addNetwork(network.name)}>
                                                    Add {network.name}
                                                </button>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                            {foundNetworks && foundNetworks.length === 0 && (
                                <div className="pt-2 text-sm text-neutral-500">No new destinations found on this server.</div>
                            )}
                        </>
                    ) : (
                        <div>Server is not validated. Validate first.</div>
                    )}
                </div>
            </div>

            {showAddModal && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setShowAddModal(false)} />
                    <div className="relative flex w-full flex-col rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                        <div className="flex items-center justify-between pb-4">
                            <h3 className="text-2xl font-bold">New Destination</h3>
                            <button type="button" onClick={() => setShowAddModal(false)}>
                                ✕
                            </button>
                        </div>
                        <div className="pb-2 text-sm">Destinations are used to segregate resources by network.</div>
                        <form className="flex flex-col gap-4" onSubmit={submitCreate}>
                            <div className="flex gap-2">
                                <label className="flex flex-col gap-1 w-full">
                                    Name
                                    <input id="server-destination-name" name="server-destination-name" required value={data.name} onChange={(e) => setData('name', e.target.value)} />
                                    {errors.name && <span className="text-error">{errors.name}</span>}
                                </label>
                                <label className="flex flex-col gap-1 w-full">
                                    Network
                                    <input id="server-destination-network" name="server-destination-network" required value={data.network} onChange={(e) => setData('network', e.target.value)} />
                                    {errors.network && <span className="text-error">{errors.network}</span>}
                                </label>
                            </div>
                            <label className="flex flex-col gap-1">
                                Select a server
                                <select
                                    id="server-destination-server-id"
                                    name="server-destination-server-id"
                                    required
                                    value={data.server_id}
                                    onChange={(e) => setData('server_id', e.target.value)}
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
