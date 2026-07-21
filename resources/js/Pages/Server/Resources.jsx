import { Deferred, router } from '@inertiajs/react';
import ServerNavbar from '../../Components/ServerNavbar';
import { useTeamChannel } from '../../hooks/useTeamChannel';

const STATUS_CLASS = {
    running: 'text-green-500',
    degraded: 'text-yellow-500',
    restarting: 'text-yellow-500',
    stopped: 'text-red-500',
};

export default function Resources({ serverNavbar, managedResources, unmanagedContainers, containerActionUrl }) {
    useTeamChannel(['ApplicationStatusChanged'], () => {
        router.reload({ only: ['managedResources', 'unmanagedContainers'] });
    });

    function refresh() {
        router.reload({ only: ['managedResources', 'unmanagedContainers'] });
    }

    function containerAction(id, action) {
        router.post(containerActionUrl, { id, action }, { preserveScroll: true });
    }

    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />
            <div className="flex flex-col h-full gap-8 md:flex-row">
                <div className="w-full">
                    <div className="flex flex-col">
                        <div className="flex gap-2">
                            <h2>Resources</h2>
                            <button type="button" onClick={refresh}>
                                Refresh
                            </button>
                        </div>
                        <div>Here you can find all resources that are managed by Coolify.</div>
                    </div>

                    <h3 className="pt-6 pb-2">Managed</h3>
                    {managedResources.length > 0 ? (
                        <table className="min-w-full">
                            <thead>
                                <tr>
                                    <th className="px-5 py-3 text-xs font-medium text-left uppercase">Project</th>
                                    <th className="px-5 py-3 text-xs font-medium text-left uppercase">Environment</th>
                                    <th className="px-5 py-3 text-xs font-medium text-left uppercase">Name</th>
                                    <th className="px-5 py-3 text-xs font-medium text-left uppercase">Type</th>
                                    <th className="px-5 py-3 text-xs font-medium text-left uppercase">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {managedResources.map((resource) => (
                                    <tr key={resource.uuid}>
                                        <td className="px-5 py-4 text-sm whitespace-nowrap">{resource.projectName}</td>
                                        <td className="px-5 py-4 text-sm whitespace-nowrap">{resource.environmentName}</td>
                                        <td className="px-5 py-4 text-sm whitespace-nowrap hover:underline">
                                            <a href={resource.link}>{resource.name}</a>
                                        </td>
                                        <td className="px-5 py-4 text-sm whitespace-nowrap">{resource.type}</td>
                                        <td
                                            className={`px-5 py-4 text-sm font-medium whitespace-nowrap ${STATUS_CLASS[resource.statusCategory] ?? ''}`}
                                        >
                                            {resource.status}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    ) : (
                        <div>No managed resources found.</div>
                    )}

                    <h3 className="pt-6 pb-2">Unmanaged</h3>
                    <Deferred data="unmanagedContainers" fallback={<div>Loading unmanaged containers...</div>}>
                        {unmanagedContainers && unmanagedContainers.length > 0 ? (
                            <table className="min-w-full">
                                <thead>
                                    <tr>
                                        <th className="px-5 py-3 text-xs font-medium text-left uppercase">Name</th>
                                        <th className="px-5 py-3 text-xs font-medium text-left uppercase">Image</th>
                                        <th className="px-5 py-3 text-xs font-medium text-left uppercase">Status</th>
                                        <th className="px-5 py-3 text-xs font-medium text-left uppercase">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {unmanagedContainers.map((container) => (
                                        <tr key={container.id}>
                                            <td className="px-5 py-4 text-sm whitespace-nowrap">{container.name}</td>
                                            <td className="px-5 py-4 text-sm whitespace-nowrap">{container.image}</td>
                                            <td className="px-5 py-4 text-sm whitespace-nowrap">{container.state}</td>
                                            <td className="flex gap-2 px-5 py-4 text-sm whitespace-nowrap">
                                                {container.state === 'running' && (
                                                    <>
                                                        <button type="button" onClick={() => containerAction(container.id, 'restart')}>
                                                            Restart
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="text-error"
                                                            onClick={() => containerAction(container.id, 'stop')}
                                                        >
                                                            Stop
                                                        </button>
                                                    </>
                                                )}
                                                {container.state === 'exited' && (
                                                    <button type="button" onClick={() => containerAction(container.id, 'start')}>
                                                        Start
                                                    </button>
                                                )}
                                                {container.state === 'restarting' && (
                                                    <button
                                                        type="button"
                                                        className="text-error"
                                                        onClick={() => containerAction(container.id, 'stop')}
                                                    >
                                                        Stop
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        ) : (
                            <div>No unmanaged resources found.</div>
                        )}
                    </Deferred>
                </div>
            </div>
        </div>
    );
}
