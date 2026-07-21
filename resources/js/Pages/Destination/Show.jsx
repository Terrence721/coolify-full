import { router, useForm } from '@inertiajs/react';

export default function Show({ destination, canUpdate, canDelete, resourcesUrl, updateUrl, deleteUrl }) {
    const { data, setData, put, processing, errors } = useForm({
        name: destination.name,
    });

    function submit(e) {
        e.preventDefault();
        put(updateUrl);
    }

    function destroy() {
        const confirmation = window.prompt(`This will delete the selected destination/network. Type the destination name to confirm:`);
        if (confirmation !== destination.name) return;
        router.delete(deleteUrl);
    }

    return (
        <div>
            <form onSubmit={submit} className="flex flex-col">
                <div className="flex items-center gap-2">
                    <h1>Destination</h1>
                    {canUpdate && (
                        <button type="submit" disabled={processing}>
                            Save
                        </button>
                    )}
                    {canDelete && destination.network !== 'coolify' && (
                        <button type="button" onClick={destroy}>
                            Delete Destination
                        </button>
                    )}
                </div>

                {destination.isStandaloneDocker ? (
                    <div className="subtitle">A simple Docker network.</div>
                ) : (
                    <div className="subtitle">A swarm Docker network. (Deprecated)</div>
                )}

                {destination.isStandaloneDocker && (
                    <div className="navbar-main">
                        <nav className="flex shrink-0 gap-6 items-center whitespace-nowrap scrollbar min-h-10">
                            <a className="dark:text-white" href={`/destination/${destination.uuid}`}>
                                General
                            </a>
                            <a href={resourcesUrl}>Resources</a>
                        </nav>
                    </div>
                )}

                <div className="flex gap-2 pt-4">
                    <label className="flex flex-col gap-1">
                        Name
                        <input
                            id="destination-show-name"
                            name="destination-show-name"
                            disabled={!canUpdate}
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                        />
                        {errors.name && <span className="text-error">{errors.name}</span>}
                    </label>
                    <label className="flex flex-col gap-1">
                        Server IP
                        <input id="destination-show-server-ip" name="destination-show-server-ip" readOnly value={destination.serverIp} />
                    </label>
                    {destination.isStandaloneDocker && (
                        <label className="flex flex-col gap-1">
                            Docker Network
                            <input id="destination-show-network" name="destination-show-network" readOnly value={destination.network} />
                        </label>
                    )}
                </div>
            </form>
        </div>
    );
}
