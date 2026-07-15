import { useForm } from '@inertiajs/react';
import ServerNavbar from '../../Components/ServerNavbar';
import ServerSidebar from '../../Components/ServerSidebar';

export default function Swarm({ serverNavbar, sidebar, deprecationNotice, isSwarmManager, isSwarmWorker, updateUrl }) {
    const { data, setData, put } = useForm({
        is_swarm_manager: isSwarmManager,
        is_swarm_worker: isSwarmWorker,
    });

    function toggle(field) {
        const next = { ...data, [field]: !data[field] };
        setData(next);
        put(updateUrl, { data: next, preserveScroll: true });
    }

    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ServerSidebar sidebar={sidebar} />
                <div className="w-full">
                    <div className="flex items-center gap-2">
                        <h2>Swarm</h2>
                        <span className="badge">Deprecated</span>
                    </div>
                    <div className="my-4 p-3 border border-warning/30 bg-warning/10 text-warning text-sm rounded">
                        {deprecationNotice}
                    </div>
                    <div className="pb-4">
                        Read the docs{' '}
                        <a className="underline dark:text-white" href="https://coolify.io/docs/knowledge-base/docker/swarm" target="_blank" rel="noreferrer">
                            here
                        </a>.
                    </div>

                    <div className="w-96 flex flex-col gap-2">
                        <label className="flex items-center gap-2">
                            <input
                                id="server-is-swarm-manager"
                                type="checkbox"
                                disabled={data.is_swarm_worker}
                                checked={data.is_swarm_manager}
                                onChange={() => toggle('is_swarm_manager')}
                            />
                            Is it a Swarm Manager?
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                id="server-is-swarm-worker"
                                type="checkbox"
                                disabled={data.is_swarm_manager}
                                checked={data.is_swarm_worker}
                                onChange={() => toggle('is_swarm_worker')}
                            />
                            Is it a Swarm Worker?
                        </label>
                    </div>
                </div>
            </div>
        </div>
    );
}
