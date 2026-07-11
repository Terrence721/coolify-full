import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function CloneMe({ project, environment, destinations, resources, defaultName, cloneUrl }) {
    const [name, setName] = useState(defaultName);
    const [destinationId, setDestinationId] = useState('');
    const [cloneVolumeData, setCloneVolumeData] = useState(false);
    const [processing, setProcessing] = useState(false);
    const { errors } = usePage().props;

    function selectDestination(id) {
        setDestinationId((current) => (current === id ? '' : id));
    }

    function submit(type) {
        setProcessing(true);
        router.post(
            cloneUrl,
            { type, name, destination_id: destinationId, clone_volume_data: cloneVolumeData },
            { preserveScroll: true, onFinish: () => setProcessing(false) },
        );
    }

    return (
        <form onSubmit={(e) => e.preventDefault()}>
            <div className="flex flex-col">
                <h1>Clone</h1>
                <div className="subtitle">Quickly clone all resources to a new project or environment.</div>
            </div>

            <label className="flex flex-col gap-1">
                New Name
                <input required value={name} onChange={(e) => setName(e.target.value)} />
                {errors.name && <span className="text-error">{errors.name}</span>}
            </label>

            <h3 className="pt-8">Destination Server</h3>
            <div className="pb-2">Choose the server and network to clone the resources to.</div>
            <div className="overflow-x-auto">
                <table className="min-w-full">
                    <thead>
                        <tr>
                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Server</th>
                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Network</th>
                        </tr>
                    </thead>
                    <tbody>
                        {destinations.map((destination) => (
                            <tr
                                key={destination.destinationId}
                                className="cursor-pointer hover:bg-coolgray-50 dark:hover:bg-coolgray-200"
                                onClick={() => selectDestination(destination.destinationId)}
                            >
                                <td
                                    className={`px-5 py-4 text-sm whitespace-nowrap dark:text-white ${
                                        destinationId === destination.destinationId
                                            ? 'bg-coollabs text-white'
                                            : 'dark:bg-coolgray-100 bg-white'
                                    }`}
                                >
                                    {destination.serverName}
                                </td>
                                <td
                                    className={`px-5 py-4 text-sm whitespace-nowrap dark:text-white ${
                                        destinationId === destination.destinationId
                                            ? 'bg-coollabs text-white'
                                            : 'dark:bg-coolgray-100 bg-white'
                                    }`}
                                >
                                    {destination.destinationName}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {errors.destination_id && <span className="text-error">{errors.destination_id}</span>}

            <h3 className="pt-8">Resources</h3>
            <div className="pb-2">These will be cloned to the new project</div>
            <div className="overflow-x-auto pt-4">
                <table className="min-w-full">
                    <thead>
                        <tr>
                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Name</th>
                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Type</th>
                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        {resources.map((resource, index) => (
                            <tr key={index}>
                                <td className="px-5 py-4 text-sm whitespace-nowrap font-bold dark:text-white">{resource.name}</td>
                                <td className="px-5 py-4 text-sm whitespace-nowrap dark:text-white">{resource.type}</td>
                                <td className="px-5 py-4 text-sm dark:text-white">{resource.description || '-'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <label className="flex items-center gap-2 pt-4">
                <input
                    type="checkbox"
                    checked={cloneVolumeData}
                    onChange={(e) => setCloneVolumeData(e.target.checked)}
                />
                Clone volume data too
            </label>

            <div className="flex gap-4 pt-4 w-full">
                <button
                    type="button"
                    className="w-full"
                    disabled={processing || !destinationId}
                    onClick={() => submit('project')}
                >
                    Clone to new Project
                </button>
                <button
                    type="button"
                    className="w-full"
                    disabled={processing || !destinationId}
                    onClick={() => submit('environment')}
                >
                    Clone to new Environment
                </button>
            </div>
        </form>
    );
}
